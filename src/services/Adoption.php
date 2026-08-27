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
 * Not from `isNew`. Craft 5 makes an unpublished draft first and then applies it, so the canonical
 * save that matters is not flagged new, and the flow differs again between the entry editor,
 * inline editing and a slideout.
 *
 * The reliable signal is a contradiction: this user is **governed** here, holds **no grant** for
 * this element, and the save **succeeded anyway**. If they were granted, {@see Authority} would
 * have said so; if they were governed and ungranted, {@see Guard} would have refused the save. The
 * only way to be standing here is that the element did not exist when permission was checked.
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
