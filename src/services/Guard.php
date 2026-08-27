<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\events\AuthorizationCheckEvent;
use craft\services\Elements;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\models\Verdict;
use justinholtweb\seclude\Plugin;
use yii\base\Event;

/**
 * Wiring Craft's authorization events to {@see Authority}.
 *
 * Craft asks eight questions; Seclude has six answers, and the mapping between them is the whole
 * content of this class. It decides nothing itself.
 *
 * **The invariant lives here.** Every handler writes `false` or leaves `null`. None of them ever
 * writes `true`. Seclude narrows what Craft's own section permissions already allow; it cannot
 * widen them, which is what makes it safe to switch on and off on a live site — and, incidentally,
 * why letting policies govern *user* elements cannot become a privilege-escalation route.
 */
class Guard extends Component
{
    public function register(): void
    {
        $events = [
            Elements::EVENT_AUTHORIZE_VIEW => [$this, 'authorizeView'],
            Elements::EVENT_AUTHORIZE_SAVE => [$this, 'authorizeSave'],
            Elements::EVENT_AUTHORIZE_CREATE_DRAFTS => [$this, 'authorizeCreateDrafts'],
            Elements::EVENT_AUTHORIZE_DUPLICATE => [$this, 'authorizeDuplicate'],
            Elements::EVENT_AUTHORIZE_DUPLICATE_AS_DRAFT => [$this, 'authorizeDuplicate'],
            Elements::EVENT_AUTHORIZE_COPY => [$this, 'authorizeDuplicate'],
            Elements::EVENT_AUTHORIZE_DELETE => [$this, 'authorizeDelete'],
            Elements::EVENT_AUTHORIZE_DELETE_FOR_SITE => [$this, 'authorizeDelete'],
        ];

        foreach ($events as $name => $handler) {
            Event::on(Elements::class, $name, $handler);
        }
    }

    public function authorizeView(AuthorizationCheckEvent $event): void
    {
        $this->apply($event, Ability::VIEW);
    }

    /**
     * Saving.
     *
     * Three different questions wear this one event:
     *
     * - a brand-new element, or Craft 5's unpublished draft standing in for one → **create**
     * - a draft → **edit or propose**, either will do; refusing a propose-only user the right to
     *   save their own draft would make the ability meaningless
     * - the canonical element → **edit**, which is also where publishing a draft lands, because
     *   `canSaveCanonical()` asks this about the canonical. That is exactly the line propose-only
     *   is meant to draw.
     */
    public function authorizeSave(AuthorizationCheckEvent $event): void
    {
        $element = $event->element;

        if ($element->id === null || $element->getIsUnpublishedDraft()) {
            $this->apply($event, Ability::CREATE);

            return;
        }

        if ($element->getIsDraft()) {
            $this->applyAny($event, [Ability::SAVE, Ability::PROPOSE]);

            return;
        }

        $this->apply($event, Ability::SAVE);
    }

    public function authorizeCreateDrafts(AuthorizationCheckEvent $event): void
    {
        if ($event->element->getIsUnpublishedDraft()) {
            $this->apply($event, Ability::CREATE);

            return;
        }

        $this->applyAny($event, [Ability::PROPOSE, Ability::SAVE]);
    }

    public function authorizeDuplicate(AuthorizationCheckEvent $event): void
    {
        $this->apply($event, Ability::DUPLICATE);
    }

    public function authorizeDelete(AuthorizationCheckEvent $event): void
    {
        $this->apply($event, Ability::DELETE);
    }

    private function apply(AuthorizationCheckEvent $event, string $ability): void
    {
        $this->record($event->element, Plugin::getInstance()->authority->check($event->element, $event->user, $ability), $ability, $event);
    }

    private function applyAny(AuthorizationCheckEvent $event, array $abilities): void
    {
        $this->record($event->element, Plugin::getInstance()->authority->checkAny($event->element, $event->user, $abilities), $abilities[0], $event);
    }

    private function record(ElementInterface $element, Verdict $verdict, string $ability, AuthorizationCheckEvent $event): void
    {
        $decision = $verdict->forAuthorizationEvent();

        if ($decision === null) {
            // Leave the event untouched. Writing `null` back would be the same thing, but another
            // plugin's handler may have already had its say and clobbering that is not Seclude's
            // business.
            return;
        }

        $event->authorized = false;

        if (Plugin::getInstance()->getSettings()->logRefusals) {
            Plugin::getInstance()->refusals->record($element, $event->user, $ability, $verdict);
        }

        Craft::debug(
            sprintf('Refused %s on element %s: %s', $ability, (string)$element->id, $verdict->reason),
            Plugin::LOG_CATEGORY,
        );
    }
}
