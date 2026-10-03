<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Element;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\elements\User;
use craft\events\ElementStructureEvent;
use craft\events\ModelEvent;
use craft\events\MoveEntryEvent;
use craft\services\Entries;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\Plugin;
use yii\base\Event;
use yii\web\ForbiddenHttpException;

/**
 * Refusing the writes that never asked.
 *
 * {@see Guard} answers Craft's authorization events, and the normal edit, delete and duplicate
 * flows all raise one. Several endpoints do not: `entries/move-to-section` checks only `canMove()`,
 * `structures/move-element` only a session flag, the asset controllers only volume permissions,
 * `users/save-user` and `users/delete-user` only user permissions. Through any of them an editor
 * could save, move or delete an element Seclude has refused them — and a move is worse than an
 * edit, because moving an entry out of a governed section or under a granted parent takes it out
 * of Seclude's reach for good.
 *
 * So this sits on the writes themselves, as a backstop behind Guard rather than a replacement for
 * it. It keeps the same invariant: every handler here can only refuse — `isValid = false` or a
 * 403 — and never permits anything Craft would not.
 *
 * ## What it leaves alone
 *
 * Only an existing, canonical element being changed by a logged-in user in their own web request
 * is judged. Creation is {@see Guard}'s (CREATE is a scope question and the element has no ID to
 * judge). Drafts and revisions are copies, not the thing anybody is granted. Nested entries are
 * left to Guard too: they are saved and deleted as a side effect of editing their owner — removing
 * a Matrix block deletes one — and judging that as "delete the owner" would refuse an ordinary
 * edit, while the endpoints this class exists for do not handle nested entries at all. Resaves, propagation,
 * the console and the queue — even when the queue runs inside an editor's session — are the site
 * maintaining itself, not the editor acting, and refusing them would break Craft rather than
 * protect anything.
 *
 * ## The stored copy, not the posted one
 *
 * By the time an element reaches `beforeSave` it carries whatever was posted, including a new
 * folder, a new section or a field value a condition grant looks at. Judged as posted, an asset
 * moved to an ungoverned volume would look ungoverned and sail through. So the verdict is taken on
 * the element as it is in the database — what the user is changing, not what they are changing it
 * into.
 */
class Backstop extends Component
{
    public function register(): void
    {
        Event::on(Element::class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            /** @var ElementInterface $element */
            $element = $event->sender;
            $this->refuseUnless($event, $element, Ability::SAVE);
        });

        Event::on(Element::class, Element::EVENT_BEFORE_DELETE, function(ModelEvent $event) {
            /** @var ElementInterface $element */
            $element = $event->sender;
            $this->refuseUnless($event, $element, Ability::DELETE);
        });

        // Placing an element in a structure is editing it: with "and everything beneath them" on,
        // moving an ungranted entry under a granted one would make it granted.
        Event::on(Element::class, Element::EVENT_BEFORE_MOVE_IN_STRUCTURE, function(ElementStructureEvent $event) {
            /** @var ElementInterface $element */
            $element = $event->sender;
            $this->refuseUnless($event, $element, Ability::SAVE);
        });

        // The move itself happens in a save a moment later, by which time the entry is already in
        // its new section and would be judged there. Judge it here, while it is still in the
        // section it is leaving. The event cannot be cancelled, so a refusal is a 403.
        Event::on(Entries::class, Entries::EVENT_BEFORE_MOVE_TO_SECTION, function(MoveEntryEvent $event) {
            $user = $this->actingUser($event->entry);

            if ($user !== null && $this->refused($event->entry, $user, Ability::SAVE)) {
                throw new ForbiddenHttpException(Craft::t('seclude', 'You are not permitted to move this entry.'));
            }
        });
    }

    private function refuseUnless(ModelEvent $event, ElementInterface $element, string $ability): void
    {
        $user = $this->actingUser($element);

        if ($user !== null && $this->refused($element, $user, $ability)) {
            $event->isValid = false;
        }
    }

    /**
     * The user this write should be judged for, or null when it should not be judged at all.
     */
    private function actingUser(ElementInterface $element): ?User
    {
        if (
            $element->id === null
            || $element->firstSave
            || $element->getIsDraft()
            || $element->getIsRevision()
            || $element->resaving
            || $element->propagating
            || ($element instanceof NestedElementInterface && $element->getOwnerId() !== null)
        ) {
            return null;
        }

        // The queue, even when it runs inside an editor's web request, is the site maintaining
        // itself. The console needs no check of its own: it has no logged-in user.
        if (Craft::$app->requestedRoute === 'queue/run') {
            return null;
        }

        return Craft::$app->getUser()->getIdentity();
    }

    private function refused(ElementInterface $element, User $user, string $ability): bool
    {
        $authority = Plugin::getInstance()->authority;
        $verdict = $authority->check($this->stored($element), $user, $ability);

        if (!$verdict->isDenied()) {
            return false;
        }

        if (Plugin::getInstance()->getSettings()->logRefusals) {
            Plugin::getInstance()->refusals->record($element, $user, $ability, $verdict);
        }

        Craft::debug(
            sprintf('Backstop refused %s on element %d: %s', $ability, (int)$element->id, $verdict->reason),
            Plugin::LOG_CATEGORY,
        );

        return true;
    }

    /**
     * The element as the database has it.
     *
     * `.seclude(false)` because this can run inside an armed listing action — a bulk delete is
     * `element-indexes/perform-action` — where the filter would otherwise hide the very element
     * being judged and the lookup would fall back to the posted copy.
     */
    private function stored(ElementInterface $element): ElementInterface
    {
        $stored = $element::find()
            ->id($element->id)
            ->siteId($element->siteId)
            ->status(null)
            ->trashed(null)
            ->seclude(false)
            ->one();

        return $stored ?? $element;
    }
}
