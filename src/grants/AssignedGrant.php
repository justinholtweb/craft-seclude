<?php

declare(strict_types=1);

namespace justinholtweb\seclude\grants;

use Craft;
use craft\db\Query;
use craft\elements\User;
use justinholtweb\seclude\models\Policy;

/**
 * Elements hand-picked for this user, one at a time, in the CP.
 *
 * The behaviour `trendyminds/isolate` provided, and still the one people reach for first. It is
 * the only grant whose answer is *content* rather than configuration, so it reads from
 * `{{%seclude_assignments}}` and never touches project config.
 */
class AssignedGrant extends BaseGrant
{
    public static function type(): string
    {
        return 'assigned';
    }

    public static function displayName(): string
    {
        return Craft::t('seclude', 'Elements assigned to them');
    }

    public function idQuery(User $user, Policy $policy): ?Query
    {
        if ($policy->id === null) {
            return null;
        }

        return (new Query())
            ->select(['id' => 'seclude_a.elementId'])
            ->from(['seclude_a' => '{{%seclude_assignments}}'])
            ->where([
                'seclude_a.policyId' => $policy->id,
                'seclude_a.userId' => $user->id,
            ]);
    }

    public function usesAssignments(): bool
    {
        return true;
    }

    public function describe(Policy $policy): string
    {
        return Craft::t('seclude', 'elements assigned to them');
    }
}
