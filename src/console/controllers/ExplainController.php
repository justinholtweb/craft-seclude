<?php

declare(strict_types=1);

namespace justinholtweb\seclude\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\seclude\Plugin;
use yii\console\ExitCode;

/**
 * "Why can't they edit it?", without a browser.
 */
class ExplainController extends Controller
{
    public $defaultAction = 'element';

    /**
     * Explain one user's access to one element.
     *
     * `$elementId` is typed as a string and cast here, rather than typed as an int: Yii hands
     * console arguments over as strings, and a typed int parameter turns a fat-fingered argument
     * into a raw PHP TypeError and a stack trace instead of a sentence.
     */
    public function actionElement(string $user, string $elementId): int
    {
        if (!ctype_digit($elementId)) {
            $this->stderr("Element ID must be a number.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $elementId = (int)$elementId;
        $identity = $this->findUser($user);

        if ($identity === null) {
            $this->stderr("No user matching “$user”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $element = Craft::$app->getElements()->getElementById($elementId);

        if ($element === null) {
            $this->stderr("No element with ID $elementId.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $report = Plugin::getInstance()->explain->explain($element, $identity);

        $this->stdout(sprintf("%s → %s\n\n", (string)$identity, (string)$element), Console::BOLD);

        if ($report['redirected']) {
            $this->stdout("Judged as: {$report['subject']} (drafts and nested entries defer to their owner)\n\n", Console::FG_CYAN);
        }

        if ($report['exempt']) {
            $this->stdout("EXEMPT: {$report['exemptReason']}\n\n", Console::FG_YELLOW);
        }

        foreach ($report['verdicts'] as $ability => $verdict) {
            $colour = match (true) {
                $verdict->isDenied() => Console::FG_RED,
                $verdict->isAllowed() => Console::FG_GREEN,
                default => Console::FG_GREY,
            };

            $this->stdout(sprintf('  %-12s ', $ability));
            $this->stdout(sprintf('%-14s', $verdict->outcomeLabel()), $colour);
            $this->stdout($verdict->reason . "\n");
        }

        $this->stdout("\nPolicies\n", Console::BOLD);

        foreach ($report['policies'] as $row) {
            $this->stdout(sprintf(
                '  %-24s %s',
                $row['policy']->handle,
                $row['applies'] ? 'applies' : ($row['skipReason'] ?? 'does not apply'),
            ), $row['applies'] ? Console::FG_GREEN : Console::FG_GREY);
            $this->stdout("\n");

            foreach ($row['grants'] as $grant) {
                $mark = $grant['matched'] === null ? '-' : ($grant['matched'] ? '✓' : '✗');
                $this->stdout(sprintf("      %s %s (%s)\n", $mark, $grant['description'], $grant['type']));
            }
        }

        if ($report['policies'] === []) {
            $this->stdout("  none cover this element type\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /** Everything a user can reach under one policy. */
    public function actionCoverage(string $user, string $policyHandle): int
    {
        $identity = $this->findUser($user);
        $policy = Plugin::getInstance()->policies->getPolicyByHandle($policyHandle);

        if ($identity === null || $policy === null) {
            $this->stderr("User or policy not found.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $ids = Plugin::getInstance()->explain->grantedIds($policy, $identity);

        $this->stdout(Craft::t('seclude', '{user} reaches {n, plural, =0{nothing} =1{one element} other{# elements}} under “{policy}”.', [
            'user' => (string)$identity,
            'n' => count($ids),
            'policy' => $policy->name,
        ]) . "\n", Console::FG_GREEN);

        foreach (array_slice($ids, 0, 50) as $id) {
            $element = Craft::$app->getElements()->getElementById($id);
            $this->stdout(sprintf("  %-8d %s\n", $id, $element !== null ? (string)$element : '?'));
        }

        if (count($ids) > 50) {
            $this->stdout(sprintf("  … and %d more\n", count($ids) - 50), Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    private function findUser(string $needle): ?\craft\elements\User
    {
        $users = Craft::$app->getUsers();

        return $users->getUserByUsernameOrEmail($needle)
            ?? (ctype_digit($needle) ? $users->getUserById((int)$needle) : null);
    }
}
