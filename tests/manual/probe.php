<?php

/**
 * Small queries the control-panel smoke test needs, kept in a file.
 *
 * Inline `php -r` through docker exec through bash is three layers of quoting, and the failure
 * mode is a parse error that looks like a plugin bug.
 *
 *   php probe.php group-uid | section-uid | describe-posted | delete-posted
 */

declare(strict_types=1);

define('CRAFT_BASE_PATH', '/var/www/html');
require CRAFT_BASE_PATH . '/vendor/autoload.php';

/** @var craft\console\Application $app */
$app = require CRAFT_BASE_PATH . '/vendor/craftcms/cms/bootstrap/console.php';

use justinholtweb\seclude\Plugin;

$what = $argv[1] ?? '';
$policies = Plugin::getInstance()->policies;

switch ($what) {
    case 'group-uid':
        echo Craft::$app->getUserGroups()->getGroupByHandle('secludeDemoEditors')?->uid ?? '';
        break;

    case 'section-uid':
        echo Craft::$app->getEntries()->getSectionByHandle('secludeDemoNotes')?->uid ?? '';
        break;

    case 'describe-posted':
        $policy = $policies->getPolicyByHandle('secludeSmokePosted');

        if ($policy === null) {
            echo 'missing';
            break;
        }

        echo implode(',', [
            count($policy->grants),
            $policy->grants[0]::type() ?? '?',
            count($policy->scope->sourceUids),
            count($policy->subjects->userGroupUids),
            implode('+', $policy->abilities->granted()),
        ]);
        break;

    case 'delete-posted':
        $policy = $policies->getPolicyByHandle('secludeSmokePosted');

        if ($policy !== null) {
            $policies->deletePolicy($policy);
        }

        echo 'ok';
        break;

    default:
        fwrite(STDERR, "Unknown probe: $what\n");
        exit(1);
}
