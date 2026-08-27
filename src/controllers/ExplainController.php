<?php

declare(strict_types=1);

namespace justinholtweb\seclude\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use justinholtweb\seclude\Plugin;
use yii\web\Response;

/**
 * "Who can edit what" — the screen that keeps a permissions plugin supportable.
 */
class ExplainController extends Controller
{
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
            // is untouched and the screen can explain an element the *viewer* cannot reach. That
            // matters — an editor holding the assign permission may well be secluded themselves.
            $element = Craft::$app->getElements()->getElementById((int)$elementId);
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
