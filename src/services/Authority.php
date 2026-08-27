<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\elements\User;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\models\Verdict;
use justinholtweb\seclude\Plugin;

/**
 * The one verdict.
 *
 * Nothing else in Seclude decides anything. The authorization events, the query filter, the index
 * sources, the Twig variable and the explain screen all ask this class and report what it says. If
 * a sixth surface ever needs guarding it asks here too — re-implementing a slice of the logic
 * locally is how a permissions plugin ends up permitting at one door what it refuses at another.
 */
class Authority extends Component
{
    /** @var array<string, Verdict> */
    private array $_cache = [];

    /**
     * Can this user do this to this element?
     *
     * The answer is one of three, and the third one matters most: see {@see Verdict::SILENT}.
     */
    public function check(ElementInterface $element, ?User $user, string $ability): Verdict
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            // Not logged in is Craft's problem, not Seclude's. Saying anything here would be
            // Seclude taking a position on anonymous access, which is Bouncer's job.
            return Verdict::silent('No identified user.');
        }

        $subject = $this->subject($element);

        // An element with no ID yet cannot be keyed — every "New entry" in every section would
        // share one cache slot, and the first verdict would answer for all of them.
        if ($subject->id === null) {
            return $this->decide($subject, $user, $ability);
        }

        $key = sprintf('%d:%d:%s', (int)$user->id, (int)$subject->id, $ability);

        return $this->_cache[$key] ??= $this->decide($subject, $user, $ability);
    }

    public function allows(ElementInterface $element, ?User $user, string $ability): bool
    {
        return !$this->check($element, $user, $ability)->isDenied();
    }

    /**
     * The best of several abilities.
     *
     * Craft asks one question where Seclude has two answers. Saving a *draft*, for instance, is
     * permitted by either "Edit" or "Propose changes" — the whole point of propose-only being that
     * drafts can be made and then not published. Silent wins over allowed, because an ungoverned
     * element must stay ungoverned however many abilities were considered.
     *
     * @param string[] $abilities
     */
    public function checkAny(ElementInterface $element, ?User $user, array $abilities): Verdict
    {
        $best = null;

        foreach ($abilities as $ability) {
            $verdict = $this->check($element, $user, $ability);

            if ($verdict->isSilent()) {
                return $verdict;
            }

            if ($verdict->isAllowed()) {
                return $verdict;
            }

            $best ??= $verdict;
        }

        return $best ?? Verdict::silent('No abilities considered.');
    }

    private function decide(ElementInterface $element, User $user, string $ability): Verdict
    {
        if ($this->isExempt($user)) {
            return Verdict::silent('The user is exempt from Seclude.');
        }

        $policies = $this->governingPolicies($element, $user);

        if ($policies === []) {
            // The safety valve. Seclude governs only what it was pointed at, so a site can install
            // it, write one policy about one section, and know that nothing else moved.
            return Verdict::silent('No policy governs this element for this user.');
        }

        // Creating is judged against the scope, not against an element: the thing being created
        // does not exist yet, so no grant can match it and the normal path would refuse every
        // "New entry" button in a governed section.
        if ($ability === Ability::CREATE) {
            foreach ($policies as $policy) {
                if ($policy->abilities->allows(Ability::CREATE)) {
                    return Verdict::allowed(
                        Craft::t('seclude', 'The “{policy}” policy permits creating.', ['policy' => $policy->name]),
                        $policy,
                    );
                }
            }

            return Verdict::denied(Craft::t('seclude', 'No policy permits creating here.'), $policies[0]);
        }

        $resolver = Plugin::getInstance()->resolver;
        $permitted = false;

        foreach ($policies as $policy) {
            if (!$policy->abilities->allows($ability)) {
                continue;
            }

            $permitted = true;

            if ($resolver->grants($policy, $user, $element)) {
                return Verdict::allowed(
                    Craft::t('seclude', 'Granted by the “{policy}” policy.', ['policy' => $policy->name]),
                    $policy,
                );
            }
        }

        // Two different refusals, and telling them apart is most of what makes the explain screen
        // useful: "you may edit, but not this one" is a content problem, while "nobody may delete
        // here" is a policy the admin wrote on purpose.
        $reason = $permitted
            ? Craft::t('seclude', 'No policy grants this user this element.')
            : Craft::t('seclude', 'No policy permits “{ability}” here.', ['ability' => Ability::label($ability)]);

        return Verdict::denied($reason, $policies[0]);
    }

    /**
     * Whether the user may create new elements anywhere in a governed scope.
     *
     * Asked instead of {@see self::check()} when there is no element yet — a brand-new entry has
     * no ID, so no grant can possibly match it, and running the normal path would refuse every
     * "New entry" button on the site.
     */
    public function checkCreate(string $elementType, ?ElementInterface $prototype, ?User $user): Verdict
    {
        $user ??= Craft::$app->getUser()->getIdentity();

        if ($user === null || $this->isExempt($user)) {
            return Verdict::silent('The user is exempt from Seclude.');
        }

        $policies = [];

        foreach (Plugin::getInstance()->policies->getPoliciesForElementType($elementType) as $policy) {
            if (!$policy->subjects->matches($user)) {
                continue;
            }

            // With no element to place, a scope that names specific sections cannot be tested
            // against one, so any policy over this element type has a say. With a prototype —
            // which Craft supplies for "New entry" in a known section — the scope is checked.
            if ($prototype !== null && !$policy->scope->contains($prototype)) {
                continue;
            }

            $policies[] = $policy;
        }

        if ($policies === []) {
            return Verdict::silent('No policy governs new elements of this type for this user.');
        }

        foreach ($policies as $policy) {
            if ($policy->abilities->allows(Ability::CREATE)) {
                return Verdict::allowed(
                    Craft::t('seclude', 'The “{policy}” policy permits creating.', ['policy' => $policy->name]),
                    $policy,
                );
            }
        }

        return Verdict::denied(
            Craft::t('seclude', 'No policy permits creating here.'),
            $policies[0],
        );
    }

    /**
     * Every enabled, evaluable policy that names this user and covers this element.
     *
     * @return Policy[]
     */
    public function governingPolicies(ElementInterface $element, User $user): array
    {
        $out = [];

        foreach (Plugin::getInstance()->policies->getPoliciesForElementType($element::class) as $policy) {
            if ($policy->subjects->matches($user) && $policy->scope->contains($element)) {
                $out[] = $policy;
            }
        }

        return $out;
    }

    /**
     * Policies that seclude this user for a given element type, whatever the element.
     *
     * What {@see QueryFilter} needs: it has a query, not an element, so scope containment becomes
     * part of the SQL rather than a test it can run up front.
     *
     * @return Policy[]
     */
    public function policiesFor(string $elementType, User $user): array
    {
        if ($this->isExempt($user)) {
            return [];
        }

        return array_values(array_filter(
            Plugin::getInstance()->policies->getPoliciesForElementType($elementType),
            static fn(Policy $policy) => $policy->subjects->matches($user),
        ));
    }

    /**
     * Whether Seclude stays out of this user's way entirely.
     *
     * Admins, unless the site has deliberately said otherwise, and anybody holding the bypass
     * permission. The permission exists so an agency's support account can be exempted without
     * being made an admin — the alternative being that somebody switches `secludeAdmins` off for
     * one person and hands out the keys to everything.
     */
    public function isExempt(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        if ($user->admin && !Plugin::getInstance()->getSettings()->secludeAdmins) {
            return true;
        }

        return $user->can(Plugin::PERMISSION_BYPASS);
    }

    /**
     * The element a decision is actually about.
     *
     * Two rewrites, and both are load-bearing:
     *
     * - **A draft or revision resolves to its canonical.** Permission belongs to the entry, not to
     *   a copy of it, and grants are recorded against canonical IDs.
     * - **A nested entry resolves to its owner.** Craft does this itself inside
     *   `Element::canView()` — but the authorization *event* fires before that method is consulted,
     *   so a Matrix block inside a granted entry would be judged on its own, match no grant, and be
     *   refused. The visible symptom is an entry that opens and then will not save.
     *
     * The walk up is bounded: a corrupt ownership chain must not turn a permission check into an
     * infinite loop.
     */
    public function subject(ElementInterface $element): ElementInterface
    {
        $seen = [];

        for ($i = 0; $i < 16; $i++) {
            if ($element->getIsDraft() || $element->getIsRevision()) {
                $canonical = $element->getCanonical(true);

                if ($canonical !== $element && !isset($seen[spl_object_id($canonical)])) {
                    $seen[spl_object_id($element)] = true;
                    $element = $canonical;

                    continue;
                }
            }

            if (!$element instanceof NestedElementInterface) {
                return $element;
            }

            $owner = $element->getOwner();

            if ($owner === null || isset($seen[spl_object_id($owner)])) {
                return $element;
            }

            $seen[spl_object_id($element)] = true;
            $element = $owner;
        }

        return $element;
    }

    /**
     * Drop every memoized decision, here and in {@see Resolver}.
     *
     * Both, always. Flushing only the verdicts leaves the grant lookups behind them stale, which
     * produces the worst kind of bug in a plugin like this: a permission that is correct on the
     * next request and wrong on this one.
     */
    public function flush(): void
    {
        $this->_cache = [];
        Plugin::getInstance()->resolver->flush();
    }
}
