<?php

declare(strict_types=1);

namespace justinholtweb\seclude\models;

use Craft;
use craft\base\Model;
use craft\elements\User;

/**
 * Who a policy secludes.
 *
 * Groups are the normal answer; named users exist for the one contractor who is not worth a group.
 * Both are stored by UID — this is project config, and an integer user ID means something
 * different on every environment.
 */
class Subjects extends Model
{
    /** @var string[] */
    public array $userGroupUids = [];

    /** @var string[] */
    public array $userUids = [];

    /**
     * Match every user with CP access.
     *
     * Rare and dangerous, so it is a deliberate third option rather than "leave both lists empty".
     * An empty policy matching everybody is how a permissions plugin locks a site's staff out on
     * the day somebody saves a half-finished policy.
     */
    public bool $allUsers = false;

    /** @var array<int, bool> */
    private array $_matches = [];

    public function isEmpty(): bool
    {
        return !$this->allUsers && $this->userGroupUids === [] && $this->userUids === [];
    }

    /**
     * Whether this policy is aimed at the given user.
     *
     * Memoized per user ID: the authorization events fire many times over one CP request — once
     * per element in a listing, several times per element on an edit page — and every call would
     * otherwise re-read the user's groups.
     */
    public function matches(User $user): bool
    {
        $key = (int)$user->id;

        return $this->_matches[$key] ??= $this->resolveMatch($user);
    }

    private function resolveMatch(User $user): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        if ($this->allUsers) {
            return true;
        }

        if ($this->userUids !== [] && in_array($user->uid, $this->userUids, true)) {
            return true;
        }

        if ($this->userGroupUids === []) {
            return false;
        }

        foreach ($user->getGroups() as $group) {
            if (in_array($group->uid, $this->userGroupUids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Users this policy names, as IDs, for the CP and the assignment screens.
     *
     * @return int[]
     */
    public function resolveUserIds(): array
    {
        if ($this->allUsers) {
            return User::find()->status(null)->ids();
        }

        $ids = [];

        if ($this->userUids !== []) {
            $ids = User::find()->status(null)->uid($this->userUids)->ids();
        }

        if ($this->userGroupUids !== []) {
            $groupIds = [];

            foreach ($this->userGroupUids as $uid) {
                $group = Craft::$app->getUserGroups()->getGroupByUid($uid);

                if ($group !== null) {
                    $groupIds[] = $group->id;
                }
            }

            if ($groupIds !== []) {
                $ids = array_merge($ids, User::find()->status(null)->groupId($groupIds)->ids());
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Descriptive labels for the CP index. Missing groups are shown, not hidden. */
    public function describe(): array
    {
        if ($this->allUsers) {
            return [Craft::t('seclude', 'Everyone')];
        }

        $out = [];

        foreach ($this->userGroupUids as $uid) {
            $group = Craft::$app->getUserGroups()->getGroupByUid($uid);
            $out[] = $group?->name ?? Craft::t('seclude', 'Missing group');
        }

        if ($this->userUids !== []) {
            $names = User::find()->status(null)->uid($this->userUids)->all();

            foreach ($names as $user) {
                $out[] = (string)$user;
            }
        }

        return $out;
    }

    /**
     * Whether every group named here still exists. Drives the unevaluable check.
     *
     * Named users are deliberately not part of it. A deleted user matches nobody, so leaving their
     * UID behind narrows the policy by exactly one person who is not there — whereas marking the
     * policy unevaluable would switch it off for every member of every group it names. Deleting
     * users is a permission non-admins can hold; it must not double as a way to lift a policy.
     */
    public function isResolvable(): bool
    {
        if ($this->allUsers) {
            return true;
        }

        foreach ($this->userGroupUids as $uid) {
            if (Craft::$app->getUserGroups()->getGroupByUid($uid) === null) {
                return false;
            }
        }

        return true;
    }

    public function getConfig(): array
    {
        return [
            'allUsers' => $this->allUsers,
            'userGroupUids' => array_values($this->userGroupUids),
            'userUids' => array_values($this->userUids),
        ];
    }
}
