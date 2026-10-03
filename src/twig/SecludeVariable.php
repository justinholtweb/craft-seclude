<?php

declare(strict_types=1);

namespace justinholtweb\seclude\twig;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\models\Verdict;
use justinholtweb\seclude\Plugin;
use yii\base\Behavior;

/**
 * `craft.seclude` — mostly for building a front-end workspace.
 *
 * Seclude is a control-panel plugin, so this is a small surface on purpose. It exists for the
 * sites that give contributors a front-end dashboard instead of CP access, and want it to show the
 * same set of pages Seclude would let them into.
 */
class SecludeVariable extends Behavior
{
    /**
     * Whether this user may do this to this element — Craft's permissions and Seclude's together.
     *
     * Safe to gate on: false for a guest, and false wherever Craft itself would refuse. Use
     * {@see self::check()} for Seclude's verdict alone, which is silent (not "no") for anything no
     * policy governs.
     */
    public function can(ElementInterface $element, string $ability = Ability::SAVE, ?User $user = null): bool
    {
        return Plugin::getInstance()->authority->permits($element, $user, $ability);
    }

    /** The full verdict, when a template wants to say *why*. */
    public function check(ElementInterface $element, string $ability = Ability::SAVE, ?User $user = null): Verdict
    {
        return Plugin::getInstance()->authority->check($element, $user, $ability);
    }

    /** Whether Seclude governs this user at all for a given element type. */
    public function governs(string $elementType, ?User $user = null): bool
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        return $user !== null && Plugin::getInstance()->authority->policiesFor($elementType, $user) !== [];
    }

    /** Whether this user is outside Seclude's reach entirely. */
    public function isExempt(?User $user = null): bool
    {
        return Plugin::getInstance()->authority->isExempt($user ?? Craft::$app->getUser()->getIdentity());
    }

    /** The policies secluding this user for an element type. */
    public function policies(string $elementType, ?User $user = null): array
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        return $user === null ? [] : Plugin::getInstance()->authority->policiesFor($elementType, $user);
    }

    public function abilities(): array
    {
        return Ability::all();
    }
}
