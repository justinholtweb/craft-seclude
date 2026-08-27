<?php

declare(strict_types=1);

namespace justinholtweb\seclude\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use justinholtweb\seclude\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handing elements to people.
 *
 * Open to non-admins holding `seclude:manageAssignments`, which is the point: the person who knows
 * which three pages a new contributor should be working on is rarely the person with the admin
 * account.
 */
class AssignmentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->canAssign()) {
            throw new ForbiddenHttpException('You are not permitted to assign elements.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $policies = array_values(array_filter(
            Plugin::getInstance()->policies->getAllPolicies(),
            static fn($policy) => $policy->usesAssignments(),
        ));

        $rows = [];

        foreach ($policies as $policy) {
            $counts = Plugin::getInstance()->assignments->assigneeCounts($policy);
            $users = $policy->subjects->resolveUserIds();

            $rows[] = [
                'policy' => $policy,
                'users' => $users === [] ? [] : User::find()->status(null)->id($users)->all(),
                'counts' => $counts,
            ];
        }

        return $this->renderTemplate('seclude/assignments/_index', [
            'rows' => $rows,
            'hasAssignmentPolicies' => $policies !== [],
        ]);
    }

    public function actionEdit(int $policyId, int $userId): Response
    {
        [$policy, $user] = $this->resolve($policyId, $userId);

        $assignments = Plugin::getInstance()->assignments;
        $ids = $assignments->assignedElementIds($policy, $user);

        $elementType = $policy->scope->elementType();
        $elements = [];

        if ($ids !== [] && $elementType !== null) {
            // `.seclude(false)`: the person doing the assigning may themselves be secluded, and the
            // filter would hide the very rows this screen exists to show.
            $elements = $elementType::find()->status(null)->id($ids)->seclude(false)->all();
        }

        return $this->renderTemplate('seclude/assignments/_edit', [
            'policy' => $policy,
            'user' => $user,
            'elements' => $elements,
            'elementType' => $elementType,
            'sources' => $this->sourcesFor($policy),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        [$policy, $user] = $this->resolve(
            (int)$request->getRequiredBodyParam('policyId'),
            (int)$request->getRequiredBodyParam('userId'),
        );

        $elementIds = array_values(array_filter(array_map('intval', (array)$request->getBodyParam('elementIds', []))));
        $actor = Craft::$app->getUser()->getIdentity();

        // The escalation guard. Without it, `seclude:manageAssignments` would let somebody grant
        // themselves — or a friend — anything on the site by way of a policy they do not control.
        $refused = $this->refuseUndelegatable($elementIds, $policy, $actor);

        if ($refused !== []) {
            Craft::$app->getSession()->setError(Craft::t('seclude', 'You can only assign elements you can reach yourself. {n} were skipped.', [
                'n' => count($refused),
            ]));

            $elementIds = array_values(array_diff($elementIds, $refused));
        }

        $result = Plugin::getInstance()->assignments->setAssignments($policy, $user, $elementIds, $actor);

        if ($refused === []) {
            Craft::$app->getSession()->setNotice(Craft::t('seclude', '{added} added, {removed} removed.', $result));
        }

        return $this->redirect('seclude/assignments');
    }

    /** @return int[] element IDs the actor may not hand over */
    private function refuseUndelegatable(array $elementIds, $policy, ?User $actor): array
    {
        if ($actor === null || $elementIds === []) {
            return [];
        }

        $assignments = Plugin::getInstance()->assignments;
        $elementType = $policy->scope->elementType();

        if ($elementType === null) {
            return $elementIds;
        }

        $refused = [];

        foreach ($elementType::find()->status(null)->id($elementIds)->seclude(false)->all() as $element) {
            if (!$assignments->canDelegate($actor, $element)) {
                $refused[] = (int)$element->id;
            }
        }

        // An ID that resolved to nothing is refused too: it is either not of this element type or
        // does not exist, and either way it has no business becoming a grant.
        $found = $elementType::find()->status(null)->id($elementIds)->seclude(false)->ids();

        return array_values(array_unique(array_merge($refused, array_diff($elementIds, array_map('intval', $found)))));
    }

    private function resolve(int $policyId, int $userId): array
    {
        $policy = Plugin::getInstance()->policies->getPolicyById($policyId);
        $user = Craft::$app->getUsers()->getUserById($userId);

        if ($policy === null || $user === null) {
            throw new NotFoundHttpException('Policy or user not found.');
        }

        if (!$policy->usesAssignments()) {
            throw new NotFoundHttpException('That policy does not use hand-assigned elements.');
        }

        if (!$policy->subjects->matches($user)) {
            throw new NotFoundHttpException('That policy does not apply to that user.');
        }

        return [$policy, $user];
    }

    /** Element sources the picker should offer, so it cannot assign outside the policy's scope. */
    private function sourcesFor($policy): ?array
    {
        $uids = $policy->scope->sourceUids;

        if ($uids === []) {
            return null;
        }

        $prefix = match ($policy->scope->type) {
            'entries' => 'section:',
            'categories' => 'group:',
            'assets' => 'volume:',
            'users' => 'group:',
            default => null,
        };

        return $prefix === null ? null : array_map(static fn(string $uid) => $prefix . $uid, $uids);
    }
}
