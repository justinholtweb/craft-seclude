<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\Plugin;

/**
 * Which elements have been handed to which user, by name.
 *
 * The only part of Seclude that is content rather than configuration, and the only table worth
 * backing up. It never goes near project config: an element ID means a different element on every
 * environment, so a deploy that carried these rows would reshuffle who can edit what.
 */
class Assignments extends Component
{
    private const TABLE = '{{%seclude_assignments}}';

    /**
     * Hand elements to a user.
     *
     * Idempotent by way of the unique index — assigning the same entry twice is a no-op, not a
     * doubled grant — and it returns how many were genuinely new so the CP can say something
     * truthful about what just happened.
     *
     * @param int[] $elementIds
     */
    public function assign(Policy $policy, User $user, array $elementIds, ?User $assignedBy = null): int
    {
        $elementIds = array_values(array_unique(array_map('intval', $elementIds)));

        if ($elementIds === [] || $policy->id === null) {
            return 0;
        }

        $existing = $this->assignedElementIds($policy, $user);
        $new = array_values(array_diff($elementIds, $existing));

        if ($new === []) {
            return 0;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $rows = [];

        foreach ($new as $elementId) {
            $rows[] = [
                $policy->id,
                (int)$user->id,
                $elementId,
                $assignedBy?->id !== null ? (int)$assignedBy->id : null,
                $now,
                $now,
                StringHelper::UUID(),
            ];
        }

        Craft::$app->getDb()->createCommand()->batchInsert(
            self::TABLE,
            ['policyId', 'userId', 'elementId', 'assignedBy', 'dateCreated', 'dateUpdated', 'uid'],
            $rows,
        )->execute();

        // Handing somebody an element changes what they are granted, so anything already decided
        // this request is now wrong.
        Plugin::getInstance()->authority->flush();

        return count($rows);
    }

    /** @param int[] $elementIds */
    public function unassign(Policy $policy, User $user, array $elementIds): int
    {
        if ($elementIds === [] || $policy->id === null) {
            return 0;
        }

        $deleted = (int)Craft::$app->getDb()->createCommand()->delete(self::TABLE, [
            'policyId' => $policy->id,
            'userId' => $user->id,
            'elementId' => array_map('intval', $elementIds),
        ])->execute();

        Plugin::getInstance()->authority->flush();

        return $deleted;
    }

    /**
     * Replace a user's assignments under one policy with exactly this set.
     *
     * What the CP screen posts. Done as a diff rather than delete-then-insert so that an
     * assignment's `dateCreated` and `assignedBy` survive an unrelated edit to the same form —
     * the audit trail is the reason those columns exist.
     *
     * @param int[] $elementIds
     */
    public function setAssignments(Policy $policy, User $user, array $elementIds, ?User $assignedBy = null): array
    {
        $elementIds = array_values(array_unique(array_map('intval', $elementIds)));
        $existing = $this->assignedElementIds($policy, $user);

        $added = $this->assign($policy, $user, array_diff($elementIds, $existing), $assignedBy);
        $removed = $this->unassign($policy, $user, array_diff($existing, $elementIds));

        return ['added' => $added, 'removed' => $removed];
    }

    /** @return int[] */
    public function assignedElementIds(Policy $policy, User $user): array
    {
        if ($policy->id === null) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['elementId'])
            ->from([self::TABLE])
            ->where(['policyId' => $policy->id, 'userId' => $user->id])
            ->column());
    }

    /** Every user who has been assigned something under this policy, with a count. */
    public function assigneeCounts(Policy $policy): array
    {
        if ($policy->id === null) {
            return [];
        }

        return (new Query())
            ->select(['userId', 'total' => 'COUNT(*)'])
            ->from([self::TABLE])
            ->where(['policyId' => $policy->id])
            ->groupBy(['userId'])
            ->pairs();
    }

    /** Everyone assigned this element, across all policies. Drives the element sidebar. */
    public function assigneesOf(ElementInterface $element): array
    {
        $ids = (new Query())
            ->select(['userId'])
            ->from([self::TABLE])
            ->where(['elementId' => (int)$element->id])
            ->distinct()
            ->column();

        return $ids === [] ? [] : User::find()->status(null)->id($ids)->all();
    }

    /**
     * Whether this person may hand that element to somebody else under this policy.
     *
     * The one place Seclude could become a privilege-escalation route, and the reason
     * `seclude:manageAssignments` exists as a separate permission from being an admin: a
     * department head can delegate their own work without being able to delegate anybody else's.
     * An admin, or anybody exempt from Seclude, is not restricted here.
     *
     * Being able to *see* the element is not enough. An assignment hands over every ability the
     * policy grants, so the assigner must hold each of those on the element themselves — otherwise
     * a view-only editor could assign an entry under an "edit and delete" policy and pass on powers
     * they never had.
     */
    public function canDelegate(User $actor, ElementInterface $element, Policy $policy): bool
    {
        if ($actor->admin || Plugin::getInstance()->authority->isExempt($actor)) {
            return true;
        }

        $authority = Plugin::getInstance()->authority;

        foreach ($policy->abilities->granted() as $ability) {
            // CREATE is judged against the scope, not an element; an assignment does not hand it
            // over.
            if ($ability !== Ability::CREATE && !$authority->permits($element, $actor, $ability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this person may manage that user's assignments at all.
     *
     * Not their own, unless they are an admin or exempt: an assigner who may hand themselves
     * elements under any assignment policy that names them has, in effect, every grant that policy
     * could give.
     */
    public function canAssignTo(User $actor, User $assignee): bool
    {
        if ($actor->admin || Plugin::getInstance()->authority->isExempt($actor)) {
            return true;
        }

        return (int)$actor->id !== (int)$assignee->id;
    }

    /**
     * Drop assignments whose policy no longer uses them.
     *
     * Foreign keys clean up deleted users and elements; nothing cleans up a policy that had its
     * "assigned elements" grant removed, and those rows would come back to life the day somebody
     * added the grant again.
     */
    public function prune(): int
    {
        $keep = [];

        foreach (Plugin::getInstance()->policies->getAllPolicies() as $policy) {
            if ($policy->usesAssignments() && $policy->id !== null) {
                $keep[] = $policy->id;
            }
        }

        $condition = $keep === [] ? ['not', ['policyId' => null]] : ['not', ['policyId' => $keep]];

        return (int)Craft::$app->getDb()->createCommand()->delete(self::TABLE, $condition)->execute();
    }

    /** Whether the policy's abilities let an assignment mean anything at all. */
    public function isMeaningful(Policy $policy): bool
    {
        return $policy->usesAssignments() && !$policy->abilities->isEmpty()
            && $policy->abilities->allows(Ability::VIEW);
    }
}
