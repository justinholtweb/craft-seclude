<?php

declare(strict_types=1);

namespace justinholtweb\seclude\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\seclude\Plugin;
use yii\console\ExitCode;

/**
 * Seclude policies from the command line.
 */
class PoliciesController extends Controller
{
    public $defaultAction = 'list';

    /** List every policy and whether it is being enforced. */
    public function actionList(): int
    {
        $policies = Plugin::getInstance()->policies->getAllPolicies();

        if ($policies === []) {
            $this->stdout("No policies.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        foreach ($policies as $policy) {
            $reasons = $policy->unevaluableReasons();

            [$state, $colour] = match (true) {
                !$policy->enabled => ['disabled', Console::FG_GREY],
                $reasons !== [] => ['NOT ENFORCED', Console::FG_YELLOW],
                default => ['enforced', Console::FG_GREEN],
            };

            $this->stdout(sprintf('%-24s ', $policy->handle), Console::BOLD);
            $this->stdout(sprintf('%-14s', $state), $colour);
            $this->stdout(sprintf(
                ' %s: %s → %s',
                $policy->scope->typeLabel(),
                implode(', ', $policy->scope->describe()),
                implode(', ', $policy->abilities->granted()) ?: 'nothing',
            ));
            $this->stdout("\n");

            foreach ($reasons as $reason) {
                $this->stdout('    ! ' . $reason . "\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }

    /** Enable a policy by handle. */
    public function actionEnable(string $handle): int
    {
        return $this->toggle($handle, true);
    }

    /** Disable a policy by handle. */
    public function actionDisable(string $handle): int
    {
        return $this->toggle($handle, false);
    }

    /**
     * Turn every policy off.
     *
     * The way back in. A permissions plugin that can only be undone through the control panel it
     * may be blocking is a plugin that eventually needs a database restore.
     */
    public function actionPanic(): int
    {
        if (!$this->confirm('Disable every Seclude policy?', true)) {
            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->policies->disableAll();

        $this->stdout("Disabled $count policies.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function toggle(string $handle, bool $enabled): int
    {
        $policies = Plugin::getInstance()->policies;
        $policy = $policies->getPolicyByHandle($handle);

        if ($policy === null) {
            $this->stderr("No policy with handle “$handle”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $policies->setEnabled($policy, $enabled);

        $this->stdout(sprintf("“%s” %s.\n", $policy->name, $enabled ? 'enabled' : 'disabled'), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
