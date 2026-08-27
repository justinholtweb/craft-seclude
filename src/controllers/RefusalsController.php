<?php

declare(strict_types=1);

namespace justinholtweb\seclude\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use justinholtweb\seclude\Plugin;
use yii\web\Response;

class RefusalsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        $rows = Plugin::getInstance()->refusals->recent(200);

        $userIds = array_values(array_unique(array_filter(array_column($rows, 'userId'))));
        $elementIds = array_values(array_unique(array_filter(array_column($rows, 'elementId'))));

        $users = $userIds === [] ? [] : User::find()->status(null)->id($userIds)->indexBy('id')->all();

        $elements = [];

        foreach ($elementIds as $id) {
            $elements[$id] = Craft::$app->getElements()->getElementById((int)$id);
        }

        return $this->renderTemplate('seclude/refusals/_index', [
            'rows' => $rows,
            'users' => $users,
            'elements' => $elements,
            'policies' => Plugin::getInstance()->policies->getAllPolicies(),
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->refusals->clear();

        Craft::$app->getSession()->setNotice(Craft::t('seclude', 'Refusal log cleared.'));

        return $this->redirect('seclude/refusals');
    }
}
