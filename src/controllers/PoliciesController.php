<?php

declare(strict_types=1);

namespace justinholtweb\seclude\controllers;

use Craft;
use craft\helpers\Cp;
use craft\web\Controller;
use justinholtweb\seclude\models\Abilities;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\models\TargetScope;
use justinholtweb\seclude\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PoliciesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Policies are permissions, and permissions are an admin's business. Handing elements out
        // under a policy somebody else wrote is a separate, lesser privilege — see
        // {@see AssignmentsController}.
        //
        // Reading is allowed with `allowAdminChanges` off; writing is not, because a policy is
        // project config and Craft would refuse the write further down with a much less helpful
        // message than this one.
        $this->requireAdmin(in_array($action->id, ['save', 'delete', 'reorder', 'toggle'], true));

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('seclude/policies/_index', [
            'policies' => Plugin::getInstance()->policies->getAllPolicies(),
            'unevaluable' => Plugin::getInstance()->policies->getUnevaluablePolicies(),
            'abilityLabels' => array_combine(Ability::all(), array_map([Ability::class, 'label'], Ability::all())),
        ]);
    }

    public function actionEdit(?int $policyId = null, ?Policy $policy = null): Response
    {
        $policies = Plugin::getInstance()->policies;

        if ($policy === null) {
            // A new policy starts from the suggested abilities rather than the model's own
            // defaults, which are deliberately all-off. See {@see Abilities::suggested()}.
            $policy = $policyId !== null
                ? $policies->getPolicyById($policyId)
                : new Policy(['abilities' => Abilities::suggested()]);

            if ($policy === null) {
                throw new NotFoundHttpException('Policy not found.');
            }
        }

        return $this->renderTemplate('seclude/policies/_edit', [
            'policy' => $policy,
            'isNew' => $policy->id === null,
            // Labels resolved here, not in Twig: `getGrantTypes()` hands back class-name strings
            // and Twig cannot call a static method on one.
            'grantOptions' => array_map(
                static fn(string $class) => ['label' => $class::displayName()],
                $policies->getGrantTypes(),
            ),
            'scopeTypes' => TargetScope::types(),
            'scopeTypeOptions' => array_map(
                static fn(string $type) => ['label' => (new TargetScope(['type' => $type]))->typeLabel(), 'value' => $type],
                TargetScope::types(),
            ),
            'abilityOptions' => array_map(
                static fn(string $a) => [
                    'value' => $a,
                    'label' => Ability::label($a),
                    'description' => Ability::description($a),
                ],
                Ability::all(),
            ),
            'sourceOptions' => $this->sourceOptions(),
            'entryTypeOptions' => $this->entryTypeOptions(),
            'userGroupOptions' => $this->userGroupOptions(),
            'relationFieldOptions' => $this->relationFieldOptions(),
            'conditionBuilderHtml' => $this->conditionBuilderHtml($policy),
            'title' => $policy->id === null
                ? Craft::t('seclude', 'New policy')
                : $policy->name,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $policies = Plugin::getInstance()->policies;

        $policyId = $request->getBodyParam('policyId');
        $policy = $policyId ? $policies->getPolicyById((int)$policyId) : new Policy();

        if ($policy === null) {
            throw new NotFoundHttpException('Policy not found.');
        }

        $policy->name = (string)$request->getBodyParam('name', '');
        $policy->handle = (string)$request->getBodyParam('handle', '');
        $policy->enabled = (bool)$request->getBodyParam('enabled', true);
        $policy->includeDescendants = (bool)$request->getBodyParam('includeDescendants', false);

        $depth = $request->getBodyParam('descendantDepth');
        $policy->descendantDepth = ($depth === '' || $depth === null) ? null : (int)$depth;

        $policy->subjects->allUsers = (bool)$request->getBodyParam('subjects.allUsers', false);
        $policy->subjects->userGroupUids = (array)$request->getBodyParam('subjects.userGroupUids', []);
        $policy->subjects->userUids = $this->userUidsFromIds((array)$request->getBodyParam('subjects.users', []));

        $policy->scope->type = (string)$request->getBodyParam('scope.type', TargetScope::TYPE_ENTRIES);
        $policy->scope->sourceUids = array_values(array_filter((array)$request->getBodyParam('scope.sourceUids.' . $policy->scope->type, [])));
        $policy->scope->entryTypeUids = array_values(array_filter((array)$request->getBodyParam('scope.entryTypeUids', [])));

        foreach (Ability::all() as $ability) {
            $policy->abilities->$ability = (bool)$request->getBodyParam("abilities.$ability", false);
        }

        $policy->grants = $this->grantsFromRequest();

        if (!$policies->savePolicy($policy)) {
            Craft::$app->getSession()->setError(Craft::t('seclude', 'Couldn’t save policy.'));

            Craft::$app->getUrlManager()->setRouteParams(['policy' => $policy]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('seclude', 'Policy saved.'));

        return $this->redirectToPostedUrl($policy);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');

        return $this->asSuccess(
            Craft::t('seclude', 'Policy deleted.'),
            ['deleted' => Plugin::getInstance()->policies->deletePolicyById($id)],
        );
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uids = json_decode(Craft::$app->getRequest()->getRequiredBodyParam('ids'), true);

        Plugin::getInstance()->policies->reorderPolicies(is_array($uids) ? $uids : []);

        return $this->asSuccess();
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $policy = Plugin::getInstance()->policies->getPolicyById((int)$request->getRequiredBodyParam('id'));

        if ($policy === null) {
            throw new NotFoundHttpException('Policy not found.');
        }

        Plugin::getInstance()->policies->setEnabled($policy, (bool)$request->getRequiredBodyParam('enabled'));

        return $this->asSuccess(Craft::t('seclude', 'Policy updated.'));
    }

    // Option lists
    // ---------------------------------------------------------------------------------------

    private function grantsFromRequest(): array
    {
        $posted = (array)Craft::$app->getRequest()->getBodyParam('grants', []);
        $policies = Plugin::getInstance()->policies;
        $grants = [];

        foreach ($posted as $type => $config) {
            if (!is_array($config) || !($config['enabled'] ?? false)) {
                continue;
            }

            unset($config['enabled']);
            $config['type'] = $type;

            // The condition builder namespaces its own inputs and does not survive being nested
            // inside `grants[…]`, so it posts at the top level and is stitched back on here.
            if ($type === 'condition') {
                $config['condition'] = (array)Craft::$app->getRequest()->getBodyParam('conditionGrant', []);
            }

            $grant = $policies->createGrant($config);

            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    /**
     * The condition builder, for an existing policy only.
     *
     * A condition is built *for* an element type, and on a new policy the element type is a select
     * box the admin has not touched yet. Rebuilding the whole builder over Ajax every time that
     * select changes is a lot of moving parts to get subtly wrong on a permissions screen, so the
     * grant asks for one save first and says so. `forProjectConfig` makes the condition serialize
     * with UIDs rather than IDs, which is what lets a policy deploy.
     */
    private function conditionBuilderHtml(Policy $policy): ?string
    {
        $elementType = $policy->scope->elementType();

        if ($policy->id === null || $elementType === null) {
            return null;
        }

        $existing = $policy->getGrantsByType('condition')[0] ?? null;
        $config = $existing?->condition ?? [];

        try {
            $condition = $config !== []
                ? Craft::$app->getConditions()->createCondition($config + ['elementType' => $elementType])
                : $elementType::createCondition();
        } catch (\Throwable $e) {
            $condition = $elementType::createCondition();
        }

        $condition->mainTag = 'div';
        $condition->id = 'seclude-condition';
        $condition->name = 'conditionGrant';
        $condition->forProjectConfig = true;

        return $condition->getBuilderHtml();
    }

    private function userUidsFromIds(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));

        if ($ids === []) {
            return [];
        }

        return \craft\elements\User::find()->status(null)->id($ids)->select(['elements.uid'])->column();
    }

    private function sourceOptions(): array
    {
        $out = [TargetScope::TYPE_ENTRIES => [], TargetScope::TYPE_CATEGORIES => [], TargetScope::TYPE_ASSETS => [], TargetScope::TYPE_USERS => []];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $out[TargetScope::TYPE_ENTRIES][] = ['label' => $section->name, 'value' => $section->uid];
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $out[TargetScope::TYPE_CATEGORIES][] = ['label' => $group->name, 'value' => $group->uid];
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $out[TargetScope::TYPE_ASSETS][] = ['label' => $volume->name, 'value' => $volume->uid];
        }

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $out[TargetScope::TYPE_USERS][] = ['label' => $group->name, 'value' => $group->uid];
        }

        return $out;
    }

    private function entryTypeOptions(): array
    {
        $out = [];

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $out[] = ['label' => $entryType->name, 'value' => $entryType->uid];
        }

        return $out;
    }

    private function userGroupOptions(): array
    {
        $out = [];

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $out[] = ['label' => $group->name, 'value' => $group->uid];
        }

        return $out;
    }

    /**
     * Relation fields, for {@see \justinholtweb\seclude\grants\RelationGrant}.
     *
     * Every field is offered rather than only the ones whose settings say they point at users:
     * a source can be changed after the fact, some plugins provide relation fields Seclude has
     * never heard of, and a grant naming a field that turns out to hold the wrong thing simply
     * matches nothing. Hiding a field somebody needs is the worse failure.
     */
    private function relationFieldOptions(): array
    {
        $out = [];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof \craft\base\ElementContainerFieldInterface) {
                continue;
            }

            if ($field instanceof \craft\fields\BaseRelationField) {
                $out[] = ['label' => $field->name, 'value' => $field->uid];
            }
        }

        usort($out, static fn($a, $b) => strcasecmp($a['label'], $b['label']));

        return $out;
    }
}
