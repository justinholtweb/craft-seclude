<?php

declare(strict_types=1);

namespace justinholtweb\seclude\grants;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use justinholtweb\seclude\models\Policy;

/**
 * Elements that point at the user, or at the same thing the user points at.
 *
 * This is the grant that makes Seclude worth installing on a site with more than a handful of
 * editors. Hand-assignment does not survive 40 editors and 2,000 entries; a "Region" field on the
 * entry and the same field on the user does, and it keeps working as content is added by people
 * who have never heard of the plugin.
 *
 * Two modes:
 *
 * - **direct** — the element's relation field contains this user.
 *   *Entry → Owner → Jane.*
 * - **shared** — the element's field and the user's field point at a common element.
 *   *Entry → Region → Midwest, and Jane → Region → Midwest.*
 */
class RelationGrant extends BaseGrant
{
    public const MODE_DIRECT = 'direct';
    public const MODE_SHARED = 'shared';

    public string $mode = self::MODE_DIRECT;

    /** The relation field on the governed element. */
    public ?string $fieldUid = null;

    /** The relation field on the user. `shared` mode only. */
    public ?string $userFieldUid = null;

    public static function type(): string
    {
        return 'relation';
    }

    public static function displayName(): string
    {
        return Craft::t('seclude', 'Elements related to them');
    }

    public function idQuery(User $user, Policy $policy): ?Query
    {
        $fieldId = $this->fieldId();

        if ($fieldId === null) {
            return null;
        }

        $query = (new Query())
            ->select(['id' => 'seclude_r.sourceId'])
            ->from(['seclude_r' => Table::RELATIONS])
            ->where(['seclude_r.fieldId' => $fieldId]);

        if ($this->mode === self::MODE_DIRECT) {
            return $query->andWhere(['seclude_r.targetId' => $user->id]);
        }

        $userFieldId = $this->userFieldId();

        if ($userFieldId === null) {
            return null;
        }

        // What the user points at, through their own field. An empty result here means the user
        // has no region, no team, no client — and so gets nothing, which is the correct answer.
        $userTargets = (new Query())
            ->select(['seclude_ur.targetId'])
            ->from(['seclude_ur' => Table::RELATIONS])
            ->where([
                'seclude_ur.fieldId' => $userFieldId,
                'seclude_ur.sourceId' => $user->id,
            ]);

        return $query->andWhere(['seclude_r.targetId' => $userTargets]);
    }

    public function unresolvableReason(Policy $policy): ?string
    {
        if ($this->fieldUid === null || $this->fieldId() === null) {
            return Craft::t('seclude', 'The relation field this grant uses no longer exists.');
        }

        if ($this->mode === self::MODE_SHARED && ($this->userFieldUid === null || $this->userFieldId() === null)) {
            return Craft::t('seclude', 'The user field this grant compares against no longer exists.');
        }

        return null;
    }

    public function describe(Policy $policy): string
    {
        $field = $this->fieldUid !== null ? Craft::$app->getFields()->getFieldByUid($this->fieldUid) : null;
        $name = $field?->name ?? Craft::t('seclude', 'a missing field');

        if ($this->mode === self::MODE_DIRECT) {
            return Craft::t('seclude', 'elements whose {field} relates to them', ['field' => $name]);
        }

        $userField = $this->userFieldUid !== null ? Craft::$app->getFields()->getFieldByUid($this->userFieldUid) : null;

        return Craft::t('seclude', 'elements whose {field} matches their own {userField}', [
            'field' => $name,
            'userField' => $userField?->name ?? Craft::t('seclude', 'missing field'),
        ]);
    }

    public function getConfig(): array
    {
        return [
            'type' => self::type(),
            'mode' => $this->mode,
            'fieldUid' => $this->fieldUid,
            'userFieldUid' => $this->userFieldUid,
        ];
    }

    private function fieldId(): ?int
    {
        if ($this->fieldUid === null) {
            return null;
        }

        $id = Craft::$app->getFields()->getFieldByUid($this->fieldUid)?->id;

        return $id !== null ? (int)$id : null;
    }

    private function userFieldId(): ?int
    {
        if ($this->userFieldUid === null) {
            return null;
        }

        $id = Craft::$app->getFields()->getFieldByUid($this->userFieldUid)?->id;

        return $id !== null ? (int)$id : null;
    }
}
