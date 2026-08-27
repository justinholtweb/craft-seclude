<?php

declare(strict_types=1);

namespace justinholtweb\seclude\grants;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\models\TargetScope;

/**
 * Elements the user made.
 *
 * "Made" means different things per element type and this is the only place that difference is
 * written down: an entry has authors (plural, since Craft 5), an asset has an uploader. Categories
 * and users have neither, so the grant refuses to be used there rather than silently matching
 * nothing — a policy whose only grant matches nothing denies everybody, and the admin deserves to
 * be told that at save time rather than by a support ticket.
 */
class AuthorGrant extends BaseGrant
{
    public static function type(): string
    {
        return 'author';
    }

    public static function displayName(): string
    {
        return Craft::t('seclude', 'Elements they authored');
    }

    public function idQuery(User $user, Policy $policy): ?Query
    {
        return match ($policy->scope->type) {
            TargetScope::TYPE_ENTRIES => (new Query())
                ->select(['id' => 'seclude_ea.entryId'])
                ->from(['seclude_ea' => Table::ENTRIES_AUTHORS])
                ->where(['seclude_ea.authorId' => $user->id]),
            TargetScope::TYPE_ASSETS => (new Query())
                ->select(['id' => 'seclude_au.id'])
                ->from(['seclude_au' => Table::ASSETS])
                ->where(['seclude_au.uploaderId' => $user->id]),
            default => null,
        };
    }

    public function unresolvableReason(Policy $policy): ?string
    {
        if (in_array($policy->scope->type, [TargetScope::TYPE_ENTRIES, TargetScope::TYPE_ASSETS], true)) {
            return null;
        }

        return Craft::t('seclude', '“Elements they authored” only works on entries and assets. {type} have no author.', [
            'type' => $policy->scope->typeLabel(),
        ]);
    }

    public function describe(Policy $policy): string
    {
        return $policy->scope->type === TargetScope::TYPE_ASSETS
            ? Craft::t('seclude', 'assets they uploaded')
            : Craft::t('seclude', 'entries they authored');
    }
}
