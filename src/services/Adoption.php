<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\events\ElementEvent;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\Plugin;

/**
 * Giving a secluded user the thing they just made.
 *
 * Without this, "may create" is a trap: the editor clicks New Entry, fills it in, saves — and
 * Craft redirects them to an edit page for an entry no grant covers, so they are refused entry to
 * their own work seconds after doing it. It is the single most confusing failure this kind of
 * plugin can produce, and it looks like data loss.
 *
 * ## How it knows a creation happened
 *
 * Not from `isNew` alone. Craft 5 makes an unpublished draft first and then applies it, so the
 * canonical save that matters is not flagged new. It *is* flagged `firstSave` — Craft sets that on
 * the first save of an element in its normal, non-draft state, applying an unpublished draft
 * included — and that flag is the gate.
 *
 * It has to be. The older signal — governed, ungranted, and saved anyway — assumed every save had
 * been through {@see Guard}, and plenty are not: a `ResaveElements` job run by the queue inside
 * an editor's own web request, Craft's move-to-section and asset-move endpoints, any plugin that
 * saves on the user's behalf. Each of those turned into a permanent hand-assignment of something
 * the editor had never been granted.
 */
class Adoption extends Component
{
    public function handleAfterSave(ElementEvent $event): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->adoptCreatedElements) {
            return;
        }

        $element = $event->element;

        // Only a creation, and only one this user made in their own request. Resaves and
        // propagation are bulk work on existing elements; the queue acts for nobody in particular,
        // even when it happens to be running inside somebody's session. (The console needs no
        // check of its own: it has no logged-in user, and the identity test below ends it.)
        if (
            !($event->isNew || $element->firstSave)
            || $element->resaving
            || $element->propagating
            || Craft::$app->requestedRoute === 'queue/run'
        ) {
            return;
        }

        // Drafts and revisions are not the thing anybody is granted; the canonical is. A nested
        // entry is governed through its owner and has no assignment of its own.
        if (
            $element->id === null
            || $element->getIsDraft()
            || $element->getIsRevision()
            || ($element instanceof NestedElementInterface && $element->getOwnerId() !== null)
        ) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || Plugin::getInstance()->authority->isExempt($user)) {
            return;
        }

        $this->adopt($element, $user);
    }

    private function adopt(ElementInterface $element, \craft\elements\User $user): void
    {
        $authority = Plugin::getInstance()->authority;
        $resolver = Plugin::getInstance()->resolver;
        $assignments = Plugin::getInstance()->assignments;

        $policies = $authority->governingPolicies($element, $user);

        if ($policies === []) {
            return;
        }

        foreach ($policies as $policy) {
            // Already theirs by some other route — authored it, matched a condition, sits under a
            // branch they hold. Nothing to record, and recording it anyway would leave a stale
            // hand-assignment behind when the dynamic grant stops matching.
            if ($resolver->grants($policy, $user, $element)) {
                return;
            }
        }

        foreach ($policies as $policy) {
            if (!$policy->abilities->allows(Ability::CREATE) || !$policy->usesAssignments()) {
                continue;
            }

            $assignments->assign($policy, $user, [(int)$element->id], $user);

            // The verdict cache was populated before this row existed and would keep refusing the
            // redirect that is about to happen.
            $authority->flush();

            return;
        }
    }
}
