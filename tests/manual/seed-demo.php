<?php

/**
 * A demo Seclude setup, for looking at the control panel by hand.
 *
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-seclude/tests/manual/seed-demo.php
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-seclude/tests/manual/seed-demo.php --clean
 *
 * Creates a user group, two editors, a section of its own with a few entries, and three policies
 * showing the different grants. Prints the URLs worth visiting. `--clean` removes all of it.
 */

declare(strict_types=1);

use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\UserGroup;
use justinholtweb\seclude\grants\AssignedGrant;
use justinholtweb\seclude\grants\AuthorGrant;
use justinholtweb\seclude\grants\ConditionGrant;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\models\TargetScope;
use justinholtweb\seclude\Plugin;

define('CRAFT_BASE_PATH', '/var/www/html');
require CRAFT_BASE_PATH . '/vendor/autoload.php';

/** @var craft\console\Application $app */
$app = require CRAFT_BASE_PATH . '/vendor/craftcms/cms/bootstrap/console.php';

const PREFIX = 'secludeDemo';
const HANDLES = ['secludeDemoAssigned', 'secludeDemoAuthors', 'secludeDemoCondition'];

$clean = in_array('--clean', $argv, true);
$plugin = Plugin::getInstance();

function flush_config(): void
{
    $pc = Craft::$app->getProjectConfig();

    if (method_exists($pc, 'saveModifiedConfigData')) {
        $pc->saveModifiedConfigData();
    }
}

if ($clean) {
    foreach (HANDLES as $handle) {
        $policy = $plugin->policies->getPolicyByHandle($handle);

        if ($policy !== null) {
            $plugin->policies->deletePolicy($policy);
            echo "Removed policy $handle\n";
        }
    }

    foreach (Entry::find()->section(PREFIX . 'Notes')->status(null)->all() as $entry) {
        Craft::$app->getElements()->deleteElement($entry, true);
    }

    $section = Craft::$app->getEntries()->getSectionByHandle(PREFIX . 'Notes');

    if ($section !== null) {
        Craft::$app->getEntries()->deleteSection($section);
        echo "Removed section\n";
    }

    foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
        if (str_starts_with($entryType->handle, PREFIX)) {
            Craft::$app->getEntries()->deleteEntryType($entryType);
        }
    }

    foreach (['seclude-demo-ana', 'seclude-demo-ben'] as $username) {
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($username);

        if ($user !== null) {
            Craft::$app->getElements()->deleteElement($user, true);
            echo "Removed user $username\n";
        }
    }

    $group = Craft::$app->getUserGroups()->getGroupByHandle(PREFIX . 'Editors');

    if ($group !== null) {
        Craft::$app->getUserGroups()->deleteGroup($group);
        echo "Removed user group\n";
    }

    flush_config();
    echo "Demo removed.\n";
    exit(0);
}

// Section + entry type
// -------------------------------------------------------------------------------------------

$entryType = Craft::$app->getEntries()->getEntryTypeByHandle(PREFIX . 'Note');

if ($entryType === null) {
    // The Title field has to be in the layout, not just switched on: an entry type without it
    // saves every entry with a null title, and the whole demo reads as "Untitled entry".
    $layout = new craft\models\FieldLayout(['type' => Entry::class]);
    $layout->setTabs([[
        'name' => 'Content',
        'elements' => [new craft\fieldlayoutelements\entries\EntryTitleField()],
    ]]);

    $entryType = new EntryType([
        'name' => 'Seclude Demo Note',
        'handle' => PREFIX . 'Note',
        'hasTitleField' => true,
        'fieldLayout' => $layout,
    ]);
    Craft::$app->getEntries()->saveEntryType($entryType);
    flush_config();
    $entryType = Craft::$app->getEntries()->getEntryTypeByHandle(PREFIX . 'Note');
}

$section = Craft::$app->getEntries()->getSectionByHandle(PREFIX . 'Notes');

if ($section === null) {
    $section = new Section([
        'name' => 'Seclude Demo Notes',
        'handle' => PREFIX . 'Notes',
        'type' => Section::TYPE_CHANNEL,
        'siteSettings' => array_map(
            static fn(int $siteId) => new Section_SiteSettings(['siteId' => $siteId, 'hasUrls' => false]),
            Craft::$app->getSites()->getAllSiteIds(),
        ),
    ]);
    $section->setEntryTypes([$entryType]);
    Craft::$app->getEntries()->saveSection($section);
    flush_config();
    $section = Craft::$app->getEntries()->getSectionByHandle(PREFIX . 'Notes');
}

// Group + users
// -------------------------------------------------------------------------------------------

$group = Craft::$app->getUserGroups()->getGroupByHandle(PREFIX . 'Editors');

if ($group === null) {
    $group = new UserGroup(['name' => 'Seclude Demo Editors', 'handle' => PREFIX . 'Editors']);
    Craft::$app->getUserGroups()->saveGroup($group);

    // The ID is null until project config is written, and assigning a user to a group with a null
    // ID silently assigns nothing.
    flush_config();
    $group = Craft::$app->getUserGroups()->getGroupByHandle(PREFIX . 'Editors');
}

$users = [];

foreach (['seclude-demo-ana' => 'Ana', 'seclude-demo-ben' => 'Ben'] as $username => $firstName) {
    $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($username);

    if ($user === null) {
        $user = new User([
            'username' => $username,
            'email' => $username . '@example.test',
            'firstName' => $firstName,
            'lastName' => 'Demo',
        ]);
        $user->setScenario(craft\base\Element::SCENARIO_ESSENTIALS);
        Craft::$app->getElements()->saveElement($user, false);
    }

    Craft::$app->getUsers()->assignUserToGroups($user->id, [$group->id]);
    $users[$firstName] = $user;
}

// Entries
// -------------------------------------------------------------------------------------------

$entries = [];

foreach (['Onboarding', 'Style guide', 'Release notes', 'Board minutes'] as $title) {
    $entry = Entry::find()->section(PREFIX . 'Notes')->title($title)->status(null)->one();

    if ($entry === null) {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'title' => $title,
        ]);

        if ($title === 'Style guide') {
            $entry->setAuthorIds([$users['Ana']->id]);
        }

        Craft::$app->getElements()->saveElement($entry, false);
    }

    $entries[$title] = $entry;
}

// Policies
// -------------------------------------------------------------------------------------------

function upsert(array $config): Policy
{
    global $plugin;

    $existing = $plugin->policies->getPolicyByHandle($config['handle']);

    if ($existing !== null) {
        $plugin->policies->deletePolicy($existing);
    }

    $policy = new Policy($config);

    if (!$plugin->policies->savePolicy($policy)) {
        throw new RuntimeException('Could not save ' . $config['handle'] . ': ' . json_encode($policy->getErrors()));
    }

    return $plugin->policies->getPolicyByHandle($config['handle']);
}

$assigned = upsert([
    'name' => 'Demo — hand-assigned notes',
    'handle' => 'secludeDemoAssigned',
    'subjects' => ['userGroupUids' => [$group->uid]],
    'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$section->uid]],
    'grants' => [new AssignedGrant()],
    'abilities' => ['view' => true, 'save' => true, 'create' => true, 'propose' => true],
]);

upsert([
    'name' => 'Demo — their own writing',
    'handle' => 'secludeDemoAuthors',
    'subjects' => ['userGroupUids' => [$group->uid]],
    'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$section->uid]],
    'grants' => [new AuthorGrant()],
    'abilities' => ['view' => true, 'save' => true, 'propose' => true],
]);

upsert([
    'name' => 'Demo — everything published',
    'handle' => 'secludeDemoCondition',
    'subjects' => ['userGroupUids' => [$group->uid]],
    'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$section->uid]],
    'grants' => [new ConditionGrant([
        'condition' => [
            'class' => craft\elements\conditions\entries\EntryCondition::class,
            'conditionRules' => [],
        ],
    ])],
    // Abilities default to off, so every one this policy means to grant is named.
    'abilities' => ['view' => true, 'propose' => true],
]);

$plugin->assignments->assign($assigned, $users['Ben'], [$entries['Onboarding']->id]);

echo "\nDemo ready.\n\n";
echo "  Policies         /admin/seclude/policies\n";
echo "  Assigned policy  /admin/seclude/policies/{$assigned->id}\n";
echo "  Assignments      /admin/seclude/assignments\n";
echo "  Ben’s picks      /admin/seclude/assignments/{$assigned->id}/{$users['Ben']->id}\n";
echo "  Explain          /admin/seclude/explain?userId={$users['Ben']->id}&elementId={$entries['Board minutes']->id}\n";
echo "\n";
echo "  policyId={$assigned->id} anaId={$users['Ana']->id} benId={$users['Ben']->id} entryId={$entries['Board minutes']->id}\n";
