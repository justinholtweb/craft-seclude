<?php

/**
 * Fixture builder for Seclude's integration checks.
 *
 * Creates its own sections, fields, groups, users and entries so the checks never depend on what
 * the shared harness happens to contain, and tears all of it down afterwards. Everything it makes
 * is prefixed `seclude` so a half-finished run is obvious and easy to clear by hand.
 */

declare(strict_types=1);

use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix as MatrixField;
use craft\fields\Users as UsersField;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\UserGroup;

final class SecludeFixture
{
    /**
     * Deliberately not just `seclude`.
     *
     * {@see self::purge()} removes everything carrying this prefix, and the manual demo in
     * `tests/manual/seed-demo.php` uses `secludeDemo` — which starts with `seclude`, so a shared
     * prefix meant running the checks silently deleted the demo out from under whoever was looking
     * at it.
     */
    public const PREFIX = 'secludeTest';

    public array $made = [];

    public Section $structure;
    public Section $channel;
    public Section $tenants;
    public CategoryGroup $categoryGroup;
    public EntryType $structureType;
    public EntryType $channelType;
    public EntryType $tenantType;
    public UserGroup $group;
    public User $editor;
    public User $other;
    public User $adminUser;
    public UsersField $ownerField;
    public EntriesField $regionField;
    public EntriesField $userRegionField;
    public MatrixField $blocksField;
    public EntryType $blockType;
    public ?Entry $nested = null;

    /** @var Entry[] */
    public array $entries = [];

    /** @var Entry[] */
    public array $tenantEntries = [];

    /** @var Category[] */
    public array $categories = [];

    public function build(): void
    {
        // Never assume a pristine harness. A previous run that died mid-build leaves fields and
        // sections behind, and the next `saveField()` then fails on a duplicate handle — quietly,
        // leaving a UID null and producing a fatal several steps later that looks nothing like
        // its cause.
        self::purge();
        $this->flushConfig();
        $this->makeFields();
        $this->makeSections();
        $this->makeCategoryGroup();
        $this->makeGroupAndUsers();
        $this->makeContent();
        $this->grantCraftPermissions();
    }

    /**
     * Project config writes are buffered inside a bare script, so an ID stays null until the
     * config is flushed — which is how `assignUserToGroups()` silently assigns nothing.
     */
    private function flushConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if (method_exists($projectConfig, 'saveModifiedConfigData')) {
            $projectConfig->saveModifiedConfigData();
        }
    }

    private function makeFields(): void
    {
        $fields = Craft::$app->getFields();

        $this->ownerField = new UsersField([
            'name' => 'Seclude Owner',
            'handle' => self::PREFIX . 'Owner',
        ]);
        $fields->saveField($this->ownerField);

        $this->regionField = new EntriesField([
            'name' => 'Seclude Region',
            'handle' => self::PREFIX . 'Region',
        ]);
        $fields->saveField($this->regionField);

        $this->userRegionField = new EntriesField([
            'name' => 'Seclude User Region',
            'handle' => self::PREFIX . 'UserRegion',
        ]);
        $fields->saveField($this->userRegionField);

        $this->flushConfig();

        // The user field layout is global, so it is extended rather than replaced — replacing it
        // would wipe every other plugin's user fields out of the shared harness.
        $userLayout = Craft::$app->getFields()->getLayoutByType(User::class);

        // Tabs go in as arrays, never as constructed objects: `setElements()` reaches for the
        // tab's layout, and a tab built standalone has none yet — "Field layout tab is missing its
        // field layout". Craft attaches the layout itself when it is handed an array.
        $tabs = $userLayout->getTabs();
        $tabs[] = [
            'name' => 'Seclude',
            'elements' => [
                new craft\fieldlayoutelements\CustomField($this->userRegionField),
            ],
        ];
        $userLayout->setTabs($tabs);
        Craft::$app->getUsers()->saveLayout($userLayout);

        $this->flushConfig();
    }

    /**
     * A field layout with the native Title field first.
     *
     * `hasTitleField` on its own is not enough — an entry type whose layout has no Title element
     * saves entries with a null title, and every one of them then reads back as "Untitled entry",
     * which makes the demo and the console output useless.
     */
    private function entryLayout(array $fields = []): FieldLayout
    {
        $layout = new FieldLayout(['type' => Entry::class]);

        $elements = [new craft\fieldlayoutelements\entries\EntryTitleField()];

        foreach ($fields as $field) {
            $elements[] = new craft\fieldlayoutelements\CustomField($field);
        }

        $layout->setTabs([['name' => 'Content', 'elements' => $elements]]);

        return $layout;
    }

    private function makeSections(): void
    {
        $entries = Craft::$app->getEntries();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $this->tenantType = new EntryType([
            'name' => 'Seclude Tenant',
            'handle' => self::PREFIX . 'Tenant',
            'hasTitleField' => true,
            'fieldLayout' => $this->entryLayout(),
        ]);
        $entries->saveEntryType($this->tenantType);

        $this->structureType = new EntryType([
            'name' => 'Seclude Page',
            'handle' => self::PREFIX . 'Page',
            'hasTitleField' => true,
            'fieldLayout' => $this->entryLayout([$this->ownerField, $this->regionField]),
        ]);
        $entries->saveEntryType($this->structureType);

        $this->channelType = new EntryType([
            'name' => 'Seclude Post',
            'handle' => self::PREFIX . 'Post',
            'hasTitleField' => true,
            'fieldLayout' => $this->entryLayout([$this->ownerField, $this->regionField]),
        ]);
        $entries->saveEntryType($this->channelType);

        $this->blockType = new EntryType([
            'name' => 'Seclude Block',
            'handle' => self::PREFIX . 'Block',
            'hasTitleField' => true,
            'fieldLayout' => $this->entryLayout(),
        ]);
        $entries->saveEntryType($this->blockType);

        $this->flushConfig();

        $this->blocksField = new MatrixField([
            'name' => 'Seclude Blocks',
            'handle' => self::PREFIX . 'Blocks',
        ]);
        $this->blocksField->setEntryTypes([$this->blockType]);
        Craft::$app->getFields()->saveField($this->blocksField);

        $this->flushConfig();

        // Rebuild the channel type's layout now that the Matrix field exists, so a channel entry
        // can own a nested entry — the case Craft's own owner-delegation handles inside
        // `canView()`, and the one Seclude has to handle itself because the authorization event
        // fires first.
        $this->channelType->setFieldLayout($this->entryLayout([$this->ownerField, $this->regionField, $this->blocksField]));
        $entries->saveEntryType($this->channelType);

        $this->flushConfig();

        $this->tenants = $this->makeSection('Seclude Tenants', self::PREFIX . 'Tenants', Section::TYPE_CHANNEL, [$this->tenantType], $siteId);
        $this->structure = $this->makeSection('Seclude Pages', self::PREFIX . 'Pages', Section::TYPE_STRUCTURE, [$this->structureType], $siteId);
        $this->channel = $this->makeSection('Seclude Posts', self::PREFIX . 'Posts', Section::TYPE_CHANNEL, [$this->channelType], $siteId);
    }

    private function makeSection(string $name, string $handle, string $type, array $entryTypes, int $siteId): Section
    {
        $section = new Section([
            'name' => $name,
            'handle' => $handle,
            'type' => $type,
            'siteSettings' => [
                new Section_SiteSettings([
                    'siteId' => $siteId,
                    'hasUrls' => false,
                ]),
            ],
        ]);

        $section->setEntryTypes($entryTypes);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException("Could not save section $handle: " . json_encode($section->getErrors()));
        }

        $this->flushConfig();

        return Craft::$app->getEntries()->getSectionByHandle($handle);
    }

    private function makeCategoryGroup(): void
    {
        $group = new CategoryGroup([
            'name' => 'Seclude Topics',
            'handle' => self::PREFIX . 'Topics',
        ]);

        // Site settings for *every* site, not just the primary one. Craft throws outright if one
        // is missing, and a multi-site harness is exactly where that bites.
        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
            $siteSettings[$siteId] = new CategoryGroup_SiteSettings([
                'siteId' => $siteId,
                'hasUrls' => false,
            ]);
        }

        $group->setSiteSettings($siteSettings);

        if (!Craft::$app->getCategories()->saveGroup($group)) {
            throw new RuntimeException('Could not save category group: ' . json_encode($group->getErrors()));
        }

        $this->flushConfig();

        $this->categoryGroup = Craft::$app->getCategories()->getGroupByHandle(self::PREFIX . 'Topics');
    }

    private function makeGroupAndUsers(): void
    {
        $groups = Craft::$app->getUserGroups();

        $group = new UserGroup([
            'name' => 'Seclude Editors',
            'handle' => self::PREFIX . 'Editors',
        ]);

        if (!$groups->saveGroup($group)) {
            throw new RuntimeException('Could not save user group: ' . json_encode($group->getErrors()));
        }

        // Flush, then re-fetch: the ID is null until project config is written, and assigning a
        // user to a group with a null ID assigns nothing at all and reports success.
        $this->flushConfig();
        $this->group = $groups->getGroupByHandle(self::PREFIX . 'Editors');

        $this->editor = $this->makeUser('seclude-editor');
        $this->other = $this->makeUser('seclude-other');
        $this->adminUser = $this->makeUser('seclude-admin', true);

        Craft::$app->getUsers()->assignUserToGroups($this->editor->id, [$this->group->id]);
        Craft::$app->getUsers()->assignUserToGroups($this->other->id, [$this->group->id]);
    }

    /**
     * Give the group real Craft permissions on the channel — and deliberately none on the
     * structure section.
     *
     * Without this the checks prove very little: a user with no Craft permissions is refused
     * everything anyway, so "Seclude denied it" and "Craft denied it" look identical. With
     * permissions on one section and not the other, both halves of the invariant become testable —
     * that Seclude narrows what Craft allows, and that it cannot widen what Craft refuses.
     */
    public function grantCraftPermissions(): void
    {
        $uid = $this->channel->uid;

        $permissions = [
            'accessCp',
            "viewEntries:$uid",
            "saveEntries:$uid",
            "createEntries:$uid",
            "deleteEntries:$uid",
            "viewPeerEntries:$uid",
            "savePeerEntries:$uid",
            "deletePeerEntries:$uid",
        ];

        // On a multi-site install Craft gates every localized element behind `editSite:<uid>`
        // before any section permission is consulted, so without these the group can edit nothing
        // anywhere and every "Craft would have allowed it" check is vacuously true.
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $permissions[] = "editSite:$site->uid";
        }

        Craft::$app->getUserPermissions()->saveGroupPermissions($this->group->id, $permissions);

        $this->flushConfig();
    }

    private function makeUser(string $username, bool $admin = false): User
    {
        $user = new User([
            'username' => $username,
            'email' => $username . '@example.test',
            'firstName' => 'Seclude',
            'lastName' => ucfirst($username),
            'admin' => $admin,
        ]);

        $user->setScenario(craft\base\Element::SCENARIO_ESSENTIALS);

        if (!Craft::$app->getElements()->saveElement($user, false)) {
            throw new RuntimeException("Could not save user $username: " . json_encode($user->getErrors()));
        }

        return $user;
    }

    private function makeContent(): void
    {
        $elements = Craft::$app->getElements();

        foreach (['North', 'South'] as $name) {
            $entry = new Entry([
                'sectionId' => $this->tenants->id,
                'typeId' => $this->tenantType->id,
                'title' => "Seclude $name",
            ]);
            $elements->saveElement($entry, false);
            $this->tenantEntries[$name] = $entry;
        }

        // A structure: root → child → grandchild, for the descendant grant.
        $root = $this->makeEntry($this->structure, $this->structureType, 'Seclude Root');
        $child = $this->makeEntry($this->structure, $this->structureType, 'Seclude Child', $root);
        $grandchild = $this->makeEntry($this->structure, $this->structureType, 'Seclude Grandchild', $child);
        $sibling = $this->makeEntry($this->structure, $this->structureType, 'Seclude Sibling');

        $this->entries = compact('root', 'child', 'grandchild', 'sibling');

        // Channel entries: one authored by the editor, one owned by them via a relation, one in
        // the North region, and one that nothing reaches.
        $this->entries['authored'] = $this->makeEntry($this->channel, $this->channelType, 'Seclude Authored', null, [
            'authorIds' => [$this->editor->id],
        ]);

        $this->entries['owned'] = $this->makeEntry($this->channel, $this->channelType, 'Seclude Owned', null, [
            'fields' => [$this->ownerField->handle => [$this->editor->id]],
        ]);

        $this->entries['north'] = $this->makeEntry($this->channel, $this->channelType, 'Seclude North Post', null, [
            'fields' => [$this->regionField->handle => [$this->tenantEntries['North']->id]],
        ]);

        $this->entries['south'] = $this->makeEntry($this->channel, $this->channelType, 'Seclude South Post', null, [
            'fields' => [$this->regionField->handle => [$this->tenantEntries['South']->id]],
        ]);

        $this->entries['stranger'] = $this->makeEntry($this->channel, $this->channelType, 'Seclude Stranger');

        // A nested entry living inside the stranger entry.
        $this->nested = new Entry([
            'fieldId' => $this->blocksField->id,
            'primaryOwnerId' => $this->entries['stranger']->id,
            'ownerId' => $this->entries['stranger']->id,
            'typeId' => $this->blockType->id,
            'title' => 'Seclude Nested',
        ]);

        if (!Craft::$app->getElements()->saveElement($this->nested, false)) {
            throw new RuntimeException('Could not save nested entry: ' . json_encode($this->nested->getErrors()));
        }

        // The editor belongs to the North region.
        $this->editor->setFieldValue($this->userRegionField->handle, [$this->tenantEntries['North']->id]);
        $this->editor->setScenario(craft\base\Element::SCENARIO_ESSENTIALS);
        Craft::$app->getElements()->saveElement($this->editor, false);

        foreach (['Alpha', 'Beta'] as $name) {
            $category = new Category([
                'groupId' => $this->categoryGroup->id,
                'title' => "Seclude $name",
            ]);
            Craft::$app->getElements()->saveElement($category, false);
            $this->categories[$name] = $category;
        }
    }

    private function makeEntry(Section $section, EntryType $type, string $title, ?Entry $parent = null, array $config = []): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $type->id,
            'title' => $title,
        ]);

        if (isset($config['authorIds'])) {
            $entry->setAuthorIds($config['authorIds']);
        }

        if ($parent !== null) {
            $entry->setParentId($parent->id);
        }

        if (isset($config['fields'])) {
            foreach ($config['fields'] as $handle => $value) {
                $entry->setFieldValue($handle, $value);
            }
        }

        if (!Craft::$app->getElements()->saveElement($entry, false)) {
            throw new RuntimeException("Could not save entry $title: " . json_encode($entry->getErrors()));
        }

        return $entry;
    }

    /**
     * Remove every `seclude`-prefixed artefact, whoever made it.
     *
     * Static and standalone so it works without a built fixture — which is exactly the case that
     * needs it.
     */
    public static function purge(): void
    {
        $elements = Craft::$app->getElements();
        $entries = Craft::$app->getEntries();

        foreach ($entries->getAllSections() as $section) {
            if (str_starts_with($section->handle, self::PREFIX)) {
                $entries->deleteSection($section);
            }
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if (str_starts_with($group->handle, self::PREFIX)) {
                Craft::$app->getCategories()->deleteGroup($group);
            }
        }

        foreach ($entries->getAllEntryTypes() as $entryType) {
            if (str_starts_with($entryType->handle, self::PREFIX)) {
                $entries->deleteEntryType($entryType);
            }
        }

        // The user layout is global and shared, so only Seclude's own tab comes out of it.
        $userLayout = Craft::$app->getFields()->getLayoutByType(User::class);
        $tabs = array_values(array_filter(
            $userLayout->getTabs(),
            static fn($tab) => $tab->name !== 'Seclude',
        ));

        if (count($tabs) !== count($userLayout->getTabs())) {
            $userLayout->setTabs($tabs);
            Craft::$app->getUsers()->saveLayout($userLayout);
        }

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if (str_starts_with($field->handle, self::PREFIX)) {
                Craft::$app->getFields()->deleteField($field);
            }
        }

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            if (str_starts_with($group->handle, self::PREFIX)) {
                Craft::$app->getUserGroups()->deleteGroup($group);
            }
        }

        foreach (User::find()->status(null)->username(['seclude-editor', 'seclude-other', 'seclude-admin'])->all() as $user) {
            $elements->deleteElement($user, true);
        }

        $pc = Craft::$app->getProjectConfig();

        if (method_exists($pc, 'saveModifiedConfigData')) {
            $pc->saveModifiedConfigData();
        }
    }

    public function tearDown(): void
    {
        $elements = Craft::$app->getElements();

        foreach ([...array_values($this->entries), ...array_values($this->tenantEntries), ...array_values($this->categories)] as $element) {
            if ($element !== null && $element->id !== null) {
                $elements->deleteElement($element, true);
            }
        }

        foreach ([$this->editor ?? null, $this->other ?? null, $this->adminUser ?? null] as $user) {
            if ($user !== null && $user->id !== null) {
                $elements->deleteElement($user, true);
            }
        }

        // Everything structural comes out through the same purge the build uses, so a failed run
        // and a clean one leave the harness in the same state.
        self::purge();

        $this->flushConfig();
    }
}
