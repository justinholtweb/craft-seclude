<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use justinholtweb\seclude\grants\GrantInterface;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\Plugin;

/**
 * "Why can't Jane edit this page?"
 *
 * The question every permissions plugin gets asked and most cannot answer, which is how sites end
 * up with an admin account shared between four people. This walks the same path {@see Authority}
 * takes and reports each step, so the answer is a screen rather than an afternoon.
 *
 * It calls {@see Authority} for the verdict rather than recomputing one. An explain screen that
 * derives its own answer will eventually disagree with the enforcement, and then it is worse than
 * having none.
 */
class Explain extends Component
{
    public function explain(ElementInterface $element, User $user): array
    {
        $authority = Plugin::getInstance()->authority;
        $resolver = Plugin::getInstance()->resolver;

        $subject = $authority->subject($element);
        $exempt = $authority->isExempt($user);

        $report = [
            'user' => $user,
            'element' => $element,
            'subject' => $subject,
            // Worth surfacing loudly: nearly every "it isn't working" report about a plugin like
            // this one is somebody testing with an account that Seclude never governs.
            'redirected' => $subject->id !== $element->id,
            'exempt' => $exempt,
            'exemptReason' => $this->exemptReason($user, $exempt),
            'policies' => [],
            'verdicts' => [],
        ];

        foreach (Plugin::getInstance()->policies->getAllPolicies() as $policy) {
            $row = $this->explainPolicy($policy, $subject, $user, $resolver);

            if ($row !== null) {
                $report['policies'][] = $row;
            }
        }

        foreach (Ability::all() as $ability) {
            $verdict = $ability === Ability::CREATE
                ? $authority->checkCreate($subject::class, $subject, $user)
                : $authority->check($subject, $user, $ability);

            $report['verdicts'][$ability] = $verdict;
        }

        return $report;
    }

    /**
     * One policy's part of the story, or null if it has nothing to do with this element type.
     *
     * Deliberately reports policies that do not apply and *why* — a policy skipped because its
     * scope misses by one section looks identical, from the outside, to a policy that is working.
     */
    private function explainPolicy(Policy $policy, ElementInterface $element, User $user, Resolver $resolver): ?array
    {
        if (!$policy->scope->coversElementType($element::class)) {
            return null;
        }

        $unevaluable = $policy->unevaluableReasons();
        $matchesUser = $policy->subjects->matches($user);
        $inScope = $policy->scope->contains($element);
        $applies = $policy->enabled && $unevaluable === [] && $matchesUser && $inScope;

        $grants = [];

        foreach ($policy->grants as $grant) {
            $grants[] = $this->explainGrant($grant, $policy, $element, $user, $applies, $resolver);
        }

        return [
            'policy' => $policy,
            'enabled' => $policy->enabled,
            'unevaluable' => $unevaluable,
            'matchesUser' => $matchesUser,
            'inScope' => $inScope,
            'applies' => $applies,
            'granted' => $applies && $resolver->grants($policy, $user, $element),
            'grants' => $grants,
            // Granted, but no individual grant matched: the element came in through the policy's
            // "and everything beneath them" expansion. Without saying so, the screen reads as a
            // contradiction — policy grants it, nothing granted it.
            'viaDescendants' => $applies
                && $policy->includeDescendants
                && $resolver->grants($policy, $user, $element)
                && !in_array(true, array_column($grants, 'matched'), true),
            'abilities' => $policy->abilities->granted(),
            'skipReason' => $this->skipReason($policy, $unevaluable, $matchesUser, $inScope),
        ];
    }

    private function explainGrant(
        GrantInterface $grant,
        Policy $policy,
        ElementInterface $element,
        User $user,
        bool $applies,
        Resolver $resolver,
    ): array {
        $matched = null;

        if ($applies) {
            // A one-grant copy of the policy, so the grant is asked in isolation. Asking the whole
            // policy would report every grant as matched the moment any one of them did, which is
            // exactly the detail somebody opened this screen to find out.
            $solo = clone $policy;
            $solo->includeDescendants = false;
            $solo->grants = [$grant];

            $matched = $resolver->evaluate($solo, $user, $element);
        }

        return [
            'type' => $grant::type(),
            'name' => $grant::displayName(),
            'description' => $grant->describe($policy),
            'matched' => $matched,
            'unresolvable' => $grant->unresolvableReason($policy),
        ];
    }

    private function skipReason(Policy $policy, array $unevaluable, bool $matchesUser, bool $inScope): ?string
    {
        if (!$policy->enabled) {
            return Craft::t('seclude', 'This policy is disabled.');
        }

        if ($unevaluable !== []) {
            return Craft::t('seclude', 'This policy cannot be evaluated, so it is not enforced.');
        }

        if (!$matchesUser) {
            return Craft::t('seclude', 'This policy does not name this user.');
        }

        if (!$inScope) {
            return Craft::t('seclude', 'This element is outside this policy’s scope.');
        }

        return null;
    }

    private function exemptReason(User $user, bool $exempt): ?string
    {
        if (!$exempt) {
            return null;
        }

        if ($user->admin && !Plugin::getInstance()->getSettings()->secludeAdmins) {
            return Craft::t('seclude', 'Admins are exempt from Seclude. Turn on “Seclude admins” in the settings to change that.');
        }

        return Craft::t('seclude', 'This user holds the “Bypass Seclude” permission.');
    }

    /**
     * Everything a user can reach in one scope. The other half of the question.
     *
     * Built on `{{%elements}}` directly rather than through an element query. The conditions
     * involved are the same ones {@see QueryFilter} hands to a prepared subquery and they speak in
     * `elements.id`; an `ElementQuery` rewrites column names in its `where` param on the way
     * through, and a permission audit that quietly means something slightly different from the
     * enforcement is worse than no audit.
     *
     * @return int[]
     */
    public function grantedIds(Policy $policy, User $user, ?int $limit = null): array
    {
        if ($policy->scope->elementType() === null) {
            return [];
        }

        $query = (new Query())
            ->select(['id' => 'elements.id'])
            ->from(['elements' => Table::ELEMENTS])
            ->where([
                'elements.dateDeleted' => null,
                'elements.draftId' => null,
                'elements.revisionId' => null,
            ])
            ->andWhere($policy->scope->inScopeCondition())
            ->andWhere(Plugin::getInstance()->resolver->grantedCondition($policy, $user));

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map('intval', $query->column());
    }
}
