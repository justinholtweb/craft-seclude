<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\controllers\ElementIndexesController;
use craft\controllers\ElementSelectorModalsController;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use justinholtweb\seclude\behaviors\SecludeQueryBehavior;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\Plugin;

/**
 * Narrowing CP listings to what a secluded user has been granted.
 *
 * Without this, Seclude would still be correct and still be useless: every refused entry would sit
 * in the index, in the search results and in the relation-field modal, taunting the person who
 * cannot open it — and leaking its title, which for a lot of the sites that want this plugin is
 * the whole point of not showing it.
 *
 * ## Armed, not global
 *
 * The filter only runs inside a known list of index and selector actions. Filtering every element
 * query in the request would be simpler to write and much worse to live with: the edit page finds
 * its own element with a query, so a refused entry would 404 instead of explaining itself; Craft's
 * routing matches a URI with a query; queue jobs, GraphQL and the front end all run queries that
 * have nothing to do with what an editor can see in the CP.
 *
 * Bouncer solved the same problem the other way — global filtering plus a flag to stand down
 * during routing — because it has to guard the front end. Seclude does not, so it can be narrow,
 * and narrow is worth more here than clever.
 */
class QueryFilter extends Component
{
    /** Element index listings, counts, exports and the structure tree. */
    private const INDEX_ACTIONS = [
        'element-indexes/get-elements',
        'element-indexes/get-more-elements',
        'element-indexes/count-elements',
        'element-indexes/get-source-tree-html',
        'element-indexes/element-table-html',
        'element-indexes/export',
        'element-indexes/perform-action',
    ];

    /** Relation-field and rich-text link pickers. */
    private const SELECTOR_ACTIONS = [
        'element-selector-modals/body',
        'element-search/search',
    ];

    private ?bool $_armed = null;

    public function handleBeforePrepare(ElementQuery $query): void
    {
        /** @var SecludeQueryBehavior|null $behavior */
        $behavior = $query->getBehavior('seclude');
        $explicit = $behavior?->getSecludeFlag();

        // `.seclude(false)` always wins, `.seclude(true)` filters outside an index action, and
        // null defers to whether this request is one of the listings the filter covers.
        if ($explicit === false || ($explicit !== true && !$this->isArmed())) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || $query->elementType === null || $query->subQuery === null) {
            return;
        }

        $policies = Plugin::getInstance()->authority->policiesFor($query->elementType, $user);

        if ($policies === []) {
            return;
        }

        $resolver = Plugin::getInstance()->resolver;

        $restricted = ['or'];
        $granted = ['or'];

        foreach ($policies as $policy) {
            $restricted[] = $policy->scope->inScopeCondition();

            // A policy that does not permit viewing still secludes its scope — it just contributes
            // nothing visible. That is what makes "may delete but not view" incoherent and why
            // {@see \justinholtweb\seclude\models\Abilities::normalize()} refuses to store it.
            if ($policy->abilities->allows(Ability::VIEW)) {
                $granted[] = $resolver->grantedCondition($policy, $user);
            }
        }

        // An `['or']` with no operands builds to an empty string and silently drops the whole
        // condition — which would show the user everything, the one outcome this class exists to
        // prevent. Say "nothing" explicitly.
        if (count($granted) === 1) {
            $granted = ['and', '1=0'];
        }

        // Read it as: leave alone anything outside every governed scope, and inside those scopes
        // show only what was granted. Elements this plugin was never pointed at are untouched.
        $query->subQuery->andWhere(['or', ['not', $restricted], $granted]);

        if (is_a($query->elementType, Entry::class, true)) {
            $query->subQuery->andWhere(['not', $this->nestedInRefusedOwner($restricted, $granted)]);
        }
    }

    /**
     * Nested entries whose owner this user may not see.
     *
     * A nested entry has no section, so it is never in a policy's scope and the condition above
     * passes it through untouched — which is right for the edit page, where {@see Authority}
     * judges it as its owner, and wrong for a listing: a Matrix field in index view, asked for
     * `ownerId=<an entry they cannot open>`, would list that entry's blocks. So a nested entry is
     * listed only if its primary owner would be.
     *
     * The inner query aliases `{{%elements}}` as `elements` on purpose: the scope and grant
     * conditions are written against `elements.id`, and there they mean the owner. One level of
     * nesting is covered; a block inside a block is judged by its immediate owner, which is itself
     * covered by this same clause wherever it is listed.
     */
    private function nestedInRefusedOwner(array $restricted, array $granted): array
    {
        $refusedOwners = (new Query())
            ->select(['elements.id'])
            ->from(['elements' => Table::ELEMENTS])
            ->where($restricted)
            ->andWhere(['not', $granted]);

        return ['exists', (new Query())
            ->from(['seclude_ne' => Table::ENTRIES])
            ->where('[[seclude_ne.id]] = [[elements.id]]')
            ->andWhere(['seclude_ne.sectionId' => null])
            ->andWhere(['seclude_ne.primaryOwnerId' => $refusedOwners]),
        ];
    }

    /**
     * Whether this request is one of the listings the filter applies to.
     *
     * Resolved once and cached: `EVENT_BEFORE_PREPARE` fires for every element query in the
     * request, and an index request runs a lot of them.
     */
    public function isArmed(): bool
    {
        return $this->_armed ??= $this->resolveArmed();
    }

    private function resolveArmed(): bool
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
            return false;
        }

        // `requestedRoute` is set by Yii before the action runs, so it is available by the time
        // any element query in that action prepares itself. The controller fallback covers a
        // route reached some other way, such as a plugin re-dispatching internally.
        $actionId = Craft::$app->requestedRoute;

        if ($actionId === null || $actionId === '') {
            $controller = Craft::$app->controller;
            $actionId = $controller !== null && $controller->action !== null
                ? $controller->getUniqueId() . '/' . $controller->action->id
                : null;
        }

        if ($actionId === null) {
            return false;
        }

        $filterSelectors = Plugin::getInstance()->getSettings()->filterSelectionModals;

        if (in_array($actionId, self::INDEX_ACTIONS, true)) {
            return true;
        }

        if ($filterSelectors && in_array($actionId, self::SELECTOR_ACTIONS, true)) {
            return true;
        }

        // Belt and braces. The route names above are the precise, intended list, but they are
        // strings, and a rename in a future Craft release would silently switch the filter off —
        // failing *open*, with every governed element back in every listing. Recognising the
        // controllers themselves cannot break that way.
        //
        // The blast radius of the fallback is small: these two controllers exist to list and
        // select elements, so filtering any action on them is at worst redundant.
        $controller = Craft::$app->controller;

        if ($controller instanceof ElementIndexesController) {
            return true;
        }

        return $filterSelectors && $controller instanceof ElementSelectorModalsController;
    }

    /** For the integration checks, which drive the filter without an HTTP request. */
    public function forceArmed(?bool $armed): void
    {
        $this->_armed = $armed;
    }
}
