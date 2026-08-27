<?php

declare(strict_types=1);

namespace justinholtweb\seclude\models;

use Craft;

/**
 * The things a policy can permit.
 *
 * These are Seclude's own vocabulary, not Craft's. Craft asks eight separate authorization
 * questions; several of them are the same question as far as an editor is concerned, so
 * {@see \justinholtweb\seclude\services\Guard} maps its events onto these five and the templates
 * only ever offer five checkboxes.
 */
class Ability
{
    /** Open the element's edit page at all. */
    public const VIEW = 'view';

    /** Save changes to the element itself — publishing a draft included. */
    public const SAVE = 'save';

    /** Create new elements in the scope. Checked against the scope, not against an element. */
    public const CREATE = 'create';

    /** Delete the element. */
    public const DELETE = 'delete';

    /** Copy the element into a new one. */
    public const DUPLICATE = 'duplicate';

    /**
     * Start a draft.
     *
     * Separate from {@see self::SAVE} because "may suggest changes, may not publish them" is the
     * single most-asked-for editorial arrangement, and Craft expresses it exactly this way:
     * `canCreateDrafts` true, `canSave` on the canonical false.
     */
    public const PROPOSE = 'propose';

    public static function all(): array
    {
        return [self::VIEW, self::SAVE, self::CREATE, self::DELETE, self::DUPLICATE, self::PROPOSE];
    }

    /**
     * Abilities that make no sense without another one.
     *
     * Saving something you cannot open is not a coherent grant, and a policy that offers it just
     * produces confusing refusals further down. {@see Abilities::normalize()} applies this.
     */
    public static function requires(string $ability): ?string
    {
        return match ($ability) {
            self::SAVE, self::DELETE, self::DUPLICATE, self::PROPOSE => self::VIEW,
            default => null,
        };
    }

    public static function label(string $ability): string
    {
        return match ($ability) {
            self::VIEW => Craft::t('seclude', 'View'),
            self::SAVE => Craft::t('seclude', 'Edit'),
            self::CREATE => Craft::t('seclude', 'Create'),
            self::DELETE => Craft::t('seclude', 'Delete'),
            self::DUPLICATE => Craft::t('seclude', 'Duplicate'),
            self::PROPOSE => Craft::t('seclude', 'Propose changes'),
            default => $ability,
        };
    }

    public static function description(string $ability): string
    {
        return match ($ability) {
            self::VIEW => Craft::t('seclude', 'Open the element and read it.'),
            self::SAVE => Craft::t('seclude', 'Save changes, and publish drafts of it.'),
            self::CREATE => Craft::t('seclude', 'Add new elements to this scope. Whatever they create is assigned to them.'),
            self::DELETE => Craft::t('seclude', 'Delete it.'),
            self::DUPLICATE => Craft::t('seclude', 'Copy it into a new element.'),
            self::PROPOSE => Craft::t('seclude', 'Create drafts. Without “Edit”, this is suggest-only: drafts can be made but not published.'),
            default => '',
        };
    }
}
