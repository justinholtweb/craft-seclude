<?php

declare(strict_types=1);

namespace justinholtweb\seclude\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\seclude\Plugin;
use yii\console\ExitCode;

class AssignmentsController extends Controller
{
    public $defaultAction = 'list';

    /** What a user has been handed by name. */
    public function actionList(string $user): int
    {
        $identity = Craft::$app->getUsers()->getUserByUsernameOrEmail($user);

        if ($identity === null) {
            $this->stderr("No user matching “$user”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $assignments = Plugin::getInstance()->assignments;
        $found = false;

        foreach (Plugin::getInstance()->policies->getAllPolicies() as $policy) {
            if (!$policy->usesAssignments()) {
                continue;
            }

            $ids = $assignments->assignedElementIds($policy, $identity);

            if ($ids === []) {
                continue;
            }

            $found = true;
            $this->stdout($policy->handle . "\n", Console::BOLD);

            foreach ($ids as $id) {
                $element = Craft::$app->getElements()->getElementById($id);

                // An assignment outlives a *trashed* element on purpose — restoring the element
                // should restore the grant with it — so say which of the two this is rather than
                // reporting both as missing.
                if ($element === null) {
                    $trashed = Craft::$app->getElements()->getElementById($id, null, null, ['trashed' => true]);
                    $label = $trashed !== null
                        ? sprintf('%s (in the trash)', (string)$trashed)
                        : '(deleted)';
                } else {
                    $label = (string)$element;
                }

                $this->stdout(sprintf("  %-8d %s\n", $id, $label));
            }
        }

        if (!$found) {
            $this->stdout("Nothing assigned by name.\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /** Remove assignments belonging to policies that no longer use them. */
    public function actionPrune(): int
    {
        $count = Plugin::getInstance()->assignments->prune();

        $this->stdout("Removed $count orphaned assignments.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
