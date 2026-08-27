<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use justinholtweb\seclude\models\Policy;

/**
 * Turning a policy's grants into SQL, once, for both callers.
 *
 * {@see Authority} asks "does this policy grant this one element"; {@see QueryFilter} asks "narrow
 * this listing to what this policy grants". Those are the same question at two scales, and this
 * class answers both from the same grant queries — which is what stops an element appearing in an
 * index and then refusing to open, the classic failure of a permissions plugin that filters in one
 * place and checks in another.
 */
class Resolver extends Component
{
    /** A condition that can never match, for when a policy grants nothing. */
    private const MATCHES_NOTHING = ['and', '1=0'];

    /** @var array<string, bool> */
    private array $_elementCache = [];

    /**
     * A SQL condition on `elements.id` for everything this policy hands the user.
     *
     * Returns an OR of one `IN (subquery)` per grant rather than a single `UNION`: an OR is
     * something every database plans well, and a `UNION` used as an `IN` subquery is the kind of
     * construction that works until somebody runs it on Postgres.
     */
    public function grantedCondition(Policy $policy, User $user): array
    {
        $queries = $this->grantQueries($policy, $user);

        if ($queries === []) {
            return self::MATCHES_NOTHING;
        }

        $condition = ['or'];

        foreach ($queries as $query) {
            $condition[] = ['elements.id' => $query];
        }

        if ($policy->includeDescendants) {
            $condition[] = $this->descendantCondition($queries, $policy->descendantDepth, null);
        }

        return $condition;
    }

    /**
     * Whether one policy grants one element to one user.
     *
     * Memoized for the request. The authorization events fire repeatedly for the same element —
     * once for view, again for save, again for delete as the CP works out which buttons to draw —
     * and each of those would otherwise be a round trip per grant.
     */
    public function grants(Policy $policy, User $user, ElementInterface $element): bool
    {
        $elementId = (int)$element->id;

        if ($elementId === 0) {
            return false;
        }

        $key = sprintf('%d:%d:%d', (int)$policy->id, (int)$user->id, $elementId);

        return $this->_elementCache[$key] ??= $this->evaluate($policy, $user, $element);
    }

    /**
     * The same question, without the memo.
     *
     * {@see \justinholtweb\seclude\services\Explain} needs this. It asks about a *cut-down copy*
     * of a policy — one grant at a time, to report which of them matched — and that copy carries
     * the original's ID, so going through {@see self::grants()} would have the two share a cache
     * entry. The explain screen would then report one grant's answer for all of them, or worse,
     * poison the real policy's cached verdict with a partial one.
     */
    public function evaluate(Policy $policy, User $user, ElementInterface $element): bool
    {
        $queries = [];

        foreach ($policy->grants as $grant) {
            // A grant that can answer directly does. `ConditionGrant` is the reason this exists:
            // asking Craft's condition about one element is free, while resolving it to an ID list
            // is a full element query.
            $direct = $grant->contains($element, $user, $policy);

            if ($direct === true) {
                return true;
            }

            $query = $grant->idQuery($user, $policy);

            if ($query === null) {
                continue;
            }

            // Still collected when `$direct` was false: the element may not match the grant
            // itself but may sit beneath something that does.
            $queries[] = $query;

            if ($direct === null && $this->queryContains($query, (int)$element->id)) {
                return true;
            }
        }

        if (!$policy->includeDescendants || $queries === []) {
            return false;
        }

        return $this->hasGrantedAncestor($queries, $policy->descendantDepth, (int)$element->id);
    }

    /** @return Query[] */
    private function grantQueries(Policy $policy, User $user): array
    {
        $queries = [];

        foreach ($policy->grants as $grant) {
            $query = $grant->idQuery($user, $policy);

            if ($query !== null) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    /**
     * Drop the memoized grant results.
     *
     * Anything that can change what a policy grants *within one request* has to call this: saving
     * a policy, and handing somebody a new assignment. {@see \justinholtweb\seclude\services\Adoption}
     * is the case that made it necessary — it assigns a just-created element and then the redirect
     * immediately re-asks a question this cache had already answered "no".
     */
    public function flush(): void
    {
        $this->_elementCache = [];
    }

    private function queryContains(Query $query, int $elementId): bool
    {
        // Wrapping the grant query as a derived table instead of adding a `WHERE` to it: the grant
        // owns its own column names, and this way the caller never has to know what they are.
        return (new Query())
            ->from(['seclude_g' => $query])
            ->where(['seclude_g.id' => $elementId])
            ->exists();
    }

    /**
     * "Sits underneath something this policy grants."
     *
     * Nested-set arithmetic on `{{%structureelements}}`: a descendant's `lft`/`rgt` fall inside its
     * ancestor's, within the same structure. One query, whatever the depth of the tree — which is
     * the whole reason to grant a branch rather than the 200 pages in it.
     *
     * @param Query[] $grantQueries
     */
    private function descendantCondition(array $grantQueries, ?int $depth, ?int $elementId): array
    {
        return ['exists', $this->descendantsQuery($grantQueries, $depth, $elementId)];
    }

    /** @param Query[] $grantQueries */
    private function descendantsQuery(array $grantQueries, ?int $depth, ?int $elementId): Query
    {
        $descendants = (new Query())
            ->from(['seclude_sd' => Table::STRUCTUREELEMENTS])
            ->innerJoin(
                ['seclude_sa' => Table::STRUCTUREELEMENTS],
                '[[seclude_sa.structureId]] = [[seclude_sd.structureId]] AND ' .
                '[[seclude_sd.lft]] > [[seclude_sa.lft]] AND ' .
                '[[seclude_sd.rgt]] < [[seclude_sa.rgt]]',
            );

        if ($elementId !== null) {
            $descendants->where(['seclude_sd.elementId' => $elementId]);
        } else {
            $descendants->where('[[seclude_sd.elementId]] = [[elements.id]]');
        }

        $ancestorMatch = ['or'];

        foreach ($grantQueries as $query) {
            $ancestorMatch[] = ['seclude_sa.elementId' => $query];
        }

        $descendants->andWhere($ancestorMatch);

        if ($depth !== null) {
            $descendants->andWhere(['<=', '[[seclude_sd.level]] - [[seclude_sa.level]]', $depth]);
        }

        return $descendants;
    }

    /**
     * @param Query[] $grantQueries
     */
    private function hasGrantedAncestor(array $grantQueries, ?int $depth, int $elementId): bool
    {
        // Run the descendants query itself rather than an `EXISTS` wrapper around it: the wrapper
        // has no `FROM` of its own, and a `SELECT` without one is a portability argument nobody
        // needs to have.
        return $this->descendantsQuery($grantQueries, $depth, $elementId)->exists();
    }
}
