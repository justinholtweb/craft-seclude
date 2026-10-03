<?php

declare(strict_types=1);

namespace justinholtweb\seclude\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use justinholtweb\seclude\models\Policy;
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

        $this->requireCpRequest();

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
        $hidden = 0;

        if ($ids !== [] && $elementType !== null) {
            // `.seclude(false)`: the person doing the assigning may themselves be secluded, and the
            // filter would hide the very rows this screen exists to show.
            $actor = Craft::$app->getUser()->getIdentity();

            foreach ($elementType::find()->status(null)->id($ids)->seclude(false)->all() as $element) {
                // An assignment somebody else made, of something this assigner could not hand
                // over themselves, is neither theirs to see nor theirs to take away. It is counted
                // rather than listed, and {@see self::actionSave()} leaves it where it is.
                if ($assignments->canDelegate($actor, $element, $policy)) {
                    $elements[] = $element;
                } else {
                    $hidden++;
                }
            }
        }

        return $this->renderTemplate('seclude/assignments/_edit', [
            'policy' => $policy,
            'user' => $user,
            'elements' => $elements,
            'hidden' => $hidden,
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

        $posted = array_values(array_unique(array_filter(array_map('intval', (array)$request->getBodyParam('elementIds', [])))));
        $actor = Craft::$app->getUser()->getIdentity();
        $assignments = Plugin::getInstance()->assignments;

        $existing = $assignments->assignedElementIds($policy, $user);
        $added = array_values(array_diff($posted, $existing));
        $removed = array_values(array_diff($existing, $posted));

        // The escalation guard, on both sides of the diff. Without it, `seclude:manageAssignments`
        // would let somebody grant themselves — or a friend — anything on the site by way of a
        // policy they do not control; and, the other way round, quietly take away what an admin
        // handed out. Rows the edit screen did not show are never in the post, so they arrive here
        // as removals and are kept.
        $refusedAdds = $this->refuseUndelegatable($added, $policy, $actor);
        $keptRemovals = $this->refuseUndelegatable($removed, $policy, $actor, removing: true);

        $result = [
            'added' => $assignments->assign($policy, $user, array_diff($added, $refusedAdds), $actor),
            'removed' => $assignments->unassign($policy, $user, array_diff($removed, $keptRemovals)),
        ];

        if ($refusedAdds !== []) {
            Craft::$app->getSession()->setError(Craft::t('seclude', 'You can only assign elements you could do everything this policy allows with yourself. {n} were skipped.', [
                'n' => count($refusedAdds),
            ]));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('seclude', '{added} added, {removed} removed.', $result));
        }

        return $this->redirect('seclude/assignments');
    }

    /**
     * Element IDs the actor may not hand over (or take back) under this policy.
     *
     * Removals that refer to an element which no longer resolves are not refused: there is nothing
     * left to protect, and keeping the row would only leave a dangling grant behind.
     *
     * @param int[] $elementIds
     * @return int[]
     */
    private function refuseUndelegatable(array $elementIds, Policy $policy, ?User $actor, bool $removing = false): array
    {
        if ($elementIds === []) {
            return [];
        }

        if ($actor === null) {
            return $elementIds;
        }

        $assignments = Plugin::getInstance()->assignments;
        $elementType = $policy->scope->elementType();

        if ($elementType === null) {
            return $elementIds;
        }

        $refused = [];
        $found = [];

        foreach ($elementType::find()->status(null)->id($elementIds)->seclude(false)->all() as $element) {
            $found[] = (int)$element->id;

            if (!$assignments->canDelegate($actor, $element, $policy)) {
                $refused[] = (int)$element->id;
            }
        }

        if ($removing) {
            return $refused;
        }

        // An ID that resolved to nothing is refused too: it is either not of this element type or
        // does not exist, and either way it has no business becoming a grant.
        return array_values(array_unique(array_merge($refused, array_diff($elementIds, $found))));
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

        if (!Plugin::getInstance()->assignments->canAssignTo(Craft::$app->getUser()->getIdentity(), $user)) {
            throw new ForbiddenHttpException('You cannot manage your own assignments.');
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
