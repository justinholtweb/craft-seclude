<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\RegisterElementSourcesEvent;
use justinholtweb\seclude\Plugin;
use Throwable;
use yii\base\Event;

/**
 * Hiding element index sources a secluded user has nothing in.
 *
 * Cosmetic in the sense that {@see QueryFilter} already empties them — and not cosmetic at all to
 * the person using the CP, who otherwise gets a sidebar full of sections that all say "No entries"
 * and no way to tell which one they are supposed to be working in. It also stops the sidebar
 * leaking the shape of the site: the *names* of the sections somebody is being kept out of.
 */
class Sources extends Component
{
    public function register(): void
    {
        foreach ([Entry::class, Category::class, Asset::class, User::class] as $elementType) {
            Event::on($elementType, Element::EVENT_REGISTER_SOURCES, function(RegisterElementSourcesEvent $event) use ($elementType) {
                $this->handleRegisterSources($event, $elementType);
            });
        }
    }

    private function handleRegisterSources(RegisterElementSourcesEvent $event, string $elementType): void
    {
        if (!Plugin::getInstance()->getSettings()->hideEmptySources) {
            return;
        }

        // No control-panel-request guard. Element sources are a control-panel concept already,
        // and the real gate is the next two lines: with no identified user, or a user no policy
        // names, this returns without touching anything. A request check on top of that bought
        // nothing except making the behaviour impossible to test outside a browser.
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return;
        }

        $policies = Plugin::getInstance()->authority->policiesFor($elementType, $user);

        if ($policies === []) {
            return;
        }

        $kept = [];
        $lastWasHeading = false;

        foreach ($event->sources as $source) {
            // Headings and dividers have no key. Keep them for now and prune the ones that end up
            // labelling nothing, so hiding the last section under a heading does not leave the
            // heading behind pointing at empty space.
            if (!isset($source['key'])) {
                if (!$lastWasHeading) {
                    $kept[] = $source;
                    $lastWasHeading = true;
                }

                continue;
            }

            // Only sources Seclude actually governs are candidates for hiding. A source's own
            // criteria carries `editable`, so testing an ungoverned one would let Seclude hide a
            // section for reasons that have nothing to do with Seclude — a section the user simply
            // lacks Craft permissions for, which Craft deliberately still shows them. Hiding it
            // would be this plugin quietly changing something it was never pointed at.
            if ($this->governs($source, $policies) && !$this->sourceHasContent($source, $elementType, $user)) {
                continue;
            }

            $kept[] = $source;
            $lastWasHeading = false;
        }

        // A trailing heading labels nothing either.
        while ($kept !== [] && !isset($kept[array_key_last($kept)]['key'])) {
            array_pop($kept);
        }

        $event->sources = $kept;
    }

    /**
     * Whether any of these policies governs the container behind this source.
     *
     * Source keys are `section:<uid>`, `group:<uid>`, `volume:<uid>`. Anything else — the `*`
     * "all" source, a custom source defined by a site or a plugin — spans governed and ungoverned
     * content at once and is always kept.
     *
     * @param \justinholtweb\seclude\models\Policy[] $policies
     */
    private function governs(array $source, array $policies): bool
    {
        $key = (string)($source['key'] ?? '');

        if (!str_contains($key, ':')) {
            return false;
        }

        $uid = explode(':', $key, 2)[1];

        foreach ($policies as $policy) {
            // An empty source list means the policy governs every container of its type.
            if ($policy->scope->sourceUids === [] || in_array($uid, $policy->scope->sourceUids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this user can see a single element in the source.
     *
     * `.seclude(true)` rather than relying on the armed filter: this runs while the index *page*
     * renders, not while a listing request is being served, so the filter would otherwise stand
     * down and every source would look occupied.
     */
    private function sourceHasContent(array $source, string $elementType, User $user): bool
    {
        $criteria = $source['criteria'] ?? null;

        if (!is_array($criteria)) {
            // A source Seclude cannot reason about — a custom source with a callback, say — is
            // kept. Hiding something because it could not be understood is how a plugin makes a
            // site's CP mysteriously lose a section.
            return true;
        }

        try {
            /** @var class-string<ElementInterface> $elementType */
            $query = $elementType::find();
            Craft::configure($query, $criteria);

            return $query
                ->status(null)
                ->siteId('*')
                ->unique()
                ->seclude(true)
                ->exists();
        } catch (Throwable $e) {
            Craft::warning('Could not test source “' . ($source['key'] ?? '?') . '”: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return true;
        }
    }
}
