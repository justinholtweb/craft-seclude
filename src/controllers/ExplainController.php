<?php

declare(strict_types=1);

namespace justinholtweb\seclude\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use justinholtweb\seclude\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * "Who can edit what" — the screen that keeps a permissions plugin supportable.
 *
 * Admins and assigners only, and CP only. The report names policies, grants and element titles,
 * and the JSON helpers list users and the IDs they can reach — every one of them is exactly what a
 * permissions plugin exists to keep from the wrong person, and a plugin controller with no checks
 * of its own is reachable by any logged-in user from the front end at `/actions/seclude/…`.
 */
class ExplainController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();

        // The JSON helpers answer for any user and any policy; only an admin may ask that.
        if ($action->id !== 'index') {
            $this->requireAdmin(false);
        } elseif (!Plugin::getInstance()->canAssign()) {
            throw new ForbiddenHttpException('You are not permitted to explain Seclude’s decisions.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();

        $userId = $request->getParam('userId');
        $elementId = $request->getParam('elementId');

        $user = $userId ? Craft::$app->getUsers()->getUserById((int)$userId) : null;
        $element = null;
        $report = null;

        if ($elementId) {
            // No `.seclude(false)` needed: this route is not one of the listings
            // {@see \justinholtweb\seclude\services\QueryFilter} arms itself for, so the lookup
            // is untouched and an admin can explain an element whoever is asking cannot reach.
            $element = Craft::$app->getElements()->getElementById((int)$elementId);

            // An assigner may be secluded themselves, and the report opens with the element's
            // title — so for anyone but an admin, an element they cannot view is one they are
            // not told about, any more than the element index would tell them.
            $viewer = Craft::$app->getUser()->getIdentity();

            if ($element !== null && !$viewer->admin && !Craft::$app->getElements()->canView($element, $viewer)) {
                $element = null;
            }
        }

        if ($user !== null && $element !== null) {
            $report = Plugin::getInstance()->explain->explain($element, $user);
        }

        return $this->renderTemplate('seclude/explain/_index', [
            'user' => $user,
            'element' => $element,
            'report' => $report,
            'policies' => Plugin::getInstance()->policies->getAllPolicies(),
        ]);
    }

    /** Everything one user can reach under one policy. The other direction of the question. */
    public function actionCoverage(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();

        $policy = Plugin::getInstance()->policies->getPolicyById((int)$request->getRequiredParam('policyId'));
        $user = Craft::$app->getUsers()->getUserById((int)$request->getRequiredParam('userId'));

        if ($policy === null || $user === null) {
            return $this->asFailure(Craft::t('seclude', 'Policy or user not found.'));
        }

        $ids = Plugin::getInstance()->explain->grantedIds($policy, $user, 500);

        return $this->asJson([
            'total' => count($ids),
            'ids' => $ids,
        ]);
    }

    /** Users the explain screen can be run against. */
    public function actionUsers(): Response
    {
        $this->requireAcceptsJson();

        $users = User::find()->status(null)->limit(200)->all();
        $out = [];

        foreach ($users as $user) {
            $out[] = ['id' => $user->id, 'label' => (string)$user];
        }

        return $this->asJson($out);
    }
}
