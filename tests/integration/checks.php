<?php

/**
 * Seclude's integration checks.
 *
 * Run inside the plugin-testing harness:
 *
 *   docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-seclude/tests/integration/checks.php
 *
 * Self-cleaning and idempotent: it builds its own sections, fields, users and content, and removes
 * all of it again whether or not the run succeeds.
 */

declare(strict_types=1);

use craft\elements\Entry;
use craft\elements\User;
use justinholtweb\seclude\grants\AssignedGrant;
use justinholtweb\seclude\grants\AuthorGrant;
use justinholtweb\seclude\grants\ConditionGrant;
use justinholtweb\seclude\grants\RelationGrant;
use justinholtweb\seclude\models\Ability;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\models\TargetScope;
use justinholtweb\seclude\models\Verdict;
use justinholtweb\seclude\Plugin;

define('CRAFT_BASE_PATH', '/var/www/html');
require CRAFT_BASE_PATH . '/vendor/autoload.php';

/** @var craft\console\Application $app */
$app = require CRAFT_BASE_PATH . '/vendor/craftcms/cms/bootstrap/console.php';

require __DIR__ . '/fixture.php';

// ---------------------------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$section = '';

function heading(string $text): void
{
    global $section;
    $section = $text;
    echo "\n\033[1m$text\033[0m\n";
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  \033[32m✓\033[0m $name\n";
    } else {
        $failed++;
        echo "  \033[31m✗\033[0m $name" . ($detail !== '' ? "  \033[31m($detail)\033[0m" : '') . "\n";
    }
}

function is_denied(Verdict $v): bool
{
    return $v->isDenied();
}

/** Seclude's whole safety story in one assertion. */
function never_grants(Verdict $v): bool
{
    return $v->forAuthorizationEvent() !== true;
}

$plugin = Plugin::getInstance();
$authority = $plugin->authority;
$policies = $plugin->policies;
$assignments = $plugin->assignments;
$resolver = $plugin->resolver;

$madePolicies = [];

/** Save a policy and remember it for teardown. */
function policy(array $config): Policy
{
    global $policies, $madePolicies;

    $policy = new Policy($config);

    if (!$policies->savePolicy($policy)) {
        throw new RuntimeException('Could not save policy: ' . json_encode($policy->getErrors()));
    }

    $madePolicies[] = $policy;

    return $policies->getPolicyByHandle($policy->handle);
}

function reset_caches(): void
{
    global $authority;
    $authority->flush();
    Craft::$app->set('seclude_resolver_reset', null);
}

$fixture = new SecludeFixture();

try {
    echo "Building fixture…\n";
    $fixture->build();
    echo "Fixture ready.\n";

    $editor = $fixture->editor;
    $other = $fixture->other;
    $admin = $fixture->adminUser;

    // -----------------------------------------------------------------------------------------
    heading('Silence when no policy governs');

    $v = $authority->check($fixture->entries['stranger'], $editor, Ability::SAVE);
    check('an ungoverned element is not Seclude’s business', $v->isSilent(), $v->reason);
    check('and the event is left alone', $v->forAuthorizationEvent() === null);

    // -----------------------------------------------------------------------------------------
    heading('Assigned elements');

    $assignedPolicy = policy([
        'name' => 'Seclude Assigned',
        'handle' => 'secludeAssigned',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'create' => false, 'delete' => false, 'duplicate' => false, 'propose' => true],
    ]);

    reset_caches();

    $v = $authority->check($fixture->entries['stranger'], $editor, Ability::SAVE);
    check('a governed element with no grant is refused', is_denied($v), $v->reason);
    check('refusal never authorises', never_grants($v));

    $assignments->assign($assignedPolicy, $editor, [$fixture->entries['stranger']->id]);
    reset_caches();

    $v = $authority->check($fixture->entries['stranger'], $editor, Ability::SAVE);
    check('assigning it permits editing', $v->isAllowed(), $v->reason);
    check('an allowed verdict still defers to Craft', $v->forAuthorizationEvent() === null);

    $v = $authority->check($fixture->entries['stranger'], $other, Ability::SAVE);
    check('the assignment belongs to one user only', is_denied($v), $v->reason);

    $v = $authority->check($fixture->entries['stranger'], $editor, Ability::DELETE);
    check('an ability the policy withholds is refused', is_denied($v), $v->reason);

    $v = $authority->check($fixture->entries['stranger'], $admin, Ability::SAVE);
    check('admins are exempt', $v->isSilent(), $v->reason);

    // Elements outside the policy's scope stay untouched.
    $v = $authority->check($fixture->entries['root'], $editor, Ability::SAVE);
    check('a different section is untouched', $v->isSilent(), $v->reason);

    // -----------------------------------------------------------------------------------------
    heading('Authorship');

    $authorPolicy = policy([
        'name' => 'Seclude Authors',
        'handle' => 'secludeAuthors',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AuthorGrant()],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
    ]);

    reset_caches();

    $v = $authority->check($fixture->entries['authored'], $editor, Ability::SAVE);
    check('an entry they authored is granted', $v->isAllowed(), $v->reason);

    $v = $authority->check($fixture->entries['authored'], $other, Ability::SAVE);
    check('somebody else’s entry is not', is_denied($v), $v->reason);

    check(
        'the author grant refuses to be used on categories',
        (new AuthorGrant())->unresolvableReason(new Policy([
            'scope' => ['type' => TargetScope::TYPE_CATEGORIES],
        ])) !== null,
    );

    // -----------------------------------------------------------------------------------------
    heading('Policies union');

    // The assigned policy and the author policy both name the editor in the same section.
    reset_caches();
    $v = $authority->check($fixture->entries['stranger'], $editor, Ability::SAVE);
    check('a grant from one policy survives another policy’s silence', $v->isAllowed(), $v->reason);

    $v = $authority->check($fixture->entries['authored'], $editor, Ability::SAVE);
    check('and vice versa', $v->isAllowed(), $v->reason);

    $policies->deletePolicy($policies->getPolicyByHandle('secludeAuthors'));
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Relations');

    $directPolicy = policy([
        'name' => 'Seclude Owners',
        'handle' => 'secludeOwners',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new RelationGrant([
            'mode' => RelationGrant::MODE_DIRECT,
            'fieldUid' => $fixture->ownerField->uid,
        ])],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
    ]);

    reset_caches();

    $v = $authority->check($fixture->entries['owned'], $editor, Ability::SAVE);
    check('an entry whose owner field names them is granted', $v->isAllowed(), $v->reason);

    $v = $authority->check($fixture->entries['owned'], $other, Ability::SAVE);
    check('and not to anybody else', is_denied($v), $v->reason);

    $policies->deletePolicy($policies->getPolicyByHandle('secludeOwners'));

    $sharedPolicy = policy([
        'name' => 'Seclude Regions',
        'handle' => 'secludeRegions',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new RelationGrant([
            'mode' => RelationGrant::MODE_SHARED,
            'fieldUid' => $fixture->regionField->uid,
            'userFieldUid' => $fixture->userRegionField->uid,
        ])],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
    ]);

    reset_caches();

    $v = $authority->check($fixture->entries['north'], $editor, Ability::SAVE);
    check('an entry in their region is granted', $v->isAllowed(), $v->reason);

    $v = $authority->check($fixture->entries['south'], $editor, Ability::SAVE);
    check('an entry in another region is refused', is_denied($v), $v->reason);

    $v = $authority->check($fixture->entries['north'], $other, Ability::SAVE);
    check('a user with no region reaches nothing', is_denied($v), $v->reason);

    $policies->deletePolicy($policies->getPolicyByHandle('secludeRegions'));
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Structure branches');

    $branchPolicy = policy([
        'name' => 'Seclude Branch',
        'handle' => 'secludeBranch',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->structure->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
        'includeDescendants' => true,
    ]);

    $assignments->assign($branchPolicy, $editor, [$fixture->entries['root']->id]);
    reset_caches();

    check('the assigned root is granted', $authority->check($fixture->entries['root'], $editor, Ability::SAVE)->isAllowed());
    check('its child comes with it', $authority->check($fixture->entries['child'], $editor, Ability::SAVE)->isAllowed());
    check('so does its grandchild', $authority->check($fixture->entries['grandchild'], $editor, Ability::SAVE)->isAllowed());
    check('a sibling does not', is_denied($authority->check($fixture->entries['sibling'], $editor, Ability::SAVE)));

    // Depth-limited.
    $branchPolicy->descendantDepth = 1;
    $policies->savePolicy($branchPolicy, false);
    reset_caches();

    check('one level down is still granted', $authority->check($fixture->entries['child'], $editor, Ability::SAVE)->isAllowed());
    check('two levels down is not', is_denied($authority->check($fixture->entries['grandchild'], $editor, Ability::SAVE)));

    $policies->deletePolicy($policies->getPolicyByHandle('secludeBranch'));
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Conditions');

    $conditionPolicy = policy([
        'name' => 'Seclude Condition',
        'handle' => 'secludeCondition',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new ConditionGrant([
            'condition' => [
                'class' => craft\elements\conditions\entries\EntryCondition::class,
                'conditionRules' => [
                    [
                        'class' => craft\elements\conditions\entries\SectionConditionRule::class,
                        'operator' => 'in',
                        'values' => [$fixture->channel->uid],
                    ],
                ],
            ],
        ])],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
    ]);

    reset_caches();

    $v = $authority->check($fixture->entries['stranger'], $editor, Ability::SAVE);
    check('a matching condition grants', $v->isAllowed(), $v->reason);

    // The two halves of a condition must agree, or an index shows what an edit page refuses.
    $grant = $conditionPolicy->grants[0];
    $viaMatch = $grant->contains($fixture->entries['stranger'], $editor, $conditionPolicy);
    $viaQuery = in_array(
        (int)$fixture->entries['stranger']->id,
        $plugin->explain->grantedIds($conditionPolicy, $editor),
        true,
    );
    check('matchElement() and the id query agree', $viaMatch === $viaQuery, var_export([$viaMatch, $viaQuery], true));

    $policies->deletePolicy($policies->getPolicyByHandle('secludeCondition'));
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Abilities');

    // The earlier policy also names this editor in this section and permits saving. Policies
    // union, so leaving it in place would (correctly) let propose-only save — which is the union
    // working, not the ability failing. Clear the field first.
    $policies->deletePolicy($policies->getPolicyByHandle('secludeAssigned'));
    reset_caches();

    $proposePolicy = policy([
        'name' => 'Seclude Propose',
        'handle' => 'secludePropose',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => false, 'create' => false, 'delete' => false, 'duplicate' => false, 'propose' => true],
    ]);

    $assignments->assign($proposePolicy, $editor, [$fixture->entries['stranger']->id]);
    reset_caches();

    check('propose-only can view', $authority->check($fixture->entries['stranger'], $editor, Ability::VIEW)->isAllowed());
    check('propose-only can draft', $authority->check($fixture->entries['stranger'], $editor, Ability::PROPOSE)->isAllowed());
    check('propose-only cannot publish', is_denied($authority->check($fixture->entries['stranger'], $editor, Ability::SAVE)));
    check('propose-only cannot delete', is_denied($authority->check($fixture->entries['stranger'], $editor, Ability::DELETE)));

    $best = $authority->checkAny($fixture->entries['stranger'], $editor, [Ability::SAVE, Ability::PROPOSE]);
    check('saving a draft is permitted by propose alone', $best->isAllowed(), $best->reason);

    // -----------------------------------------------------------------------------------------
    heading('Abilities default closed');

    $bare = new justinholtweb\seclude\models\Abilities();
    check('a bare abilities set permits nothing', $bare->granted() === [], json_encode($bare->granted()));

    $partial = new justinholtweb\seclude\models\Abilities(['view' => true]);
    check(
        'a partial set permits only what it names',
        $partial->granted() === [Ability::VIEW],
        json_encode($partial->granted()),
    );

    check(
        'the suggested set is view, edit and propose',
        justinholtweb\seclude\models\Abilities::suggested()->granted() === [Ability::VIEW, Ability::SAVE, Ability::PROPOSE],
    );

    // -----------------------------------------------------------------------------------------
    heading('Abilities normalise');

    $abilities = new justinholtweb\seclude\models\Abilities([
        'view' => false, 'save' => true, 'delete' => true, 'propose' => true,
    ]);
    $abilities->normalize();
    check('save without view is repaired, not stored', !$abilities->save && !$abilities->delete && !$abilities->propose);

    // -----------------------------------------------------------------------------------------
    heading('Drafts and nested entries resolve');

    $draft = Craft::$app->getDrafts()->createDraft($fixture->entries['stranger'], (int)$editor->id);
    $subject = $authority->subject($draft);
    check('a draft is judged as its canonical', (int)$subject->id === (int)$fixture->entries['stranger']->id, (string)$subject->id);

    $v = $authority->check($draft, $editor, Ability::PROPOSE);
    check('and inherits the canonical’s grant', $v->isAllowed(), $v->reason);

    Craft::$app->getElements()->deleteElement($draft, true);

    // -----------------------------------------------------------------------------------------
    heading('The query filter');

    $plugin->queryFilter->forceArmed(true);
    Craft::$app->getUser()->setIdentity($editor);
    reset_caches();

    $visible = Entry::find()->sectionId($fixture->channel->id)->status(null)->ids();
    check('only the granted entry is listed', $visible === [(int)$fixture->entries['stranger']->id], json_encode($visible));

    $untouched = Entry::find()->sectionId($fixture->structure->id)->status(null)->ids();
    check('an ungoverned section is not filtered', count($untouched) === 4, (string)count($untouched));

    $optedOut = Entry::find()->sectionId($fixture->channel->id)->status(null)->seclude(false)->ids();
    check('.seclude(false) opts a query out', count($optedOut) === 5, (string)count($optedOut));

    Craft::$app->getUser()->setIdentity($admin);
    reset_caches();
    $asAdmin = Entry::find()->sectionId($fixture->channel->id)->status(null)->ids();
    check('an exempt user sees everything', count($asAdmin) === 5, (string)count($asAdmin));

    Craft::$app->getUser()->setIdentity($editor);
    reset_caches();

    check(
        'the index and the verdict agree on every entry in scope',
        (function() use ($fixture, $authority, $editor) {
            $listed = Entry::find()->sectionId($fixture->channel->id)->status(null)->ids();

            foreach (Entry::find()->sectionId($fixture->channel->id)->status(null)->seclude(false)->all() as $entry) {
                $allowed = $authority->check($entry, $editor, Ability::VIEW)->isAllowed();

                if ($allowed !== in_array((int)$entry->id, array_map('intval', $listed), true)) {
                    return false;
                }
            }

            return true;
        })(),
    );

    $plugin->queryFilter->forceArmed(null);
    Craft::$app->getUser()->setIdentity(null);
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Index sources');

    // The sources event only fires for CP requests, so the service is asked directly. What is
    // under test is the pruning decision, not Craft's request detection.
    $sourcesFor = static function(User $who) use ($fixture) {
        // Sources are memoized twice over: `Element::sources()` keeps a private static keyed by
        // class and context, and the `elementSources` service keeps its own copy. Both are right
        // in production, where a request has exactly one user — and both have to go here, or all
        // three of these calls get the first user's answer.
        $prop = (new ReflectionClass(craft\base\Element::class))->getProperty('sources');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        Craft::$app->set('elementSources', new craft\services\ElementSources());

        Craft::$app->getUser()->setIdentity($who);

        $sources = Craft::$app->getElementSources()->getSources(Entry::class, 'index');
        $keys = array_column(array_filter($sources, static fn($s) => isset($s['key'])), 'key');

        Craft::$app->getUser()->setIdentity(null);

        return $keys;
    };

    $adminSources = $sourcesFor($admin);
    check(
        'the governed section is there for an exempt user',
        in_array('section:' . $fixture->channel->uid, $adminSources, true),
        json_encode($adminSources),
    );

    check(
        'the ungoverned section is there too',
        in_array('section:' . $fixture->structure->uid, $adminSources, true),
    );

    // The editor is granted exactly one entry in the channel, so the source stays; nothing in the
    // structure section is governed at all, so that one is untouched.
    $editorSources = $sourcesFor($editor);
    check(
        'a source the user can reach survives',
        in_array('section:' . $fixture->channel->uid, $editorSources, true),
        json_encode($editorSources),
    );

    check(
        'an ungoverned source is never pruned',
        in_array('section:' . $fixture->structure->uid, $editorSources, true),
    );

    // Now take the grant away and the governed source should disappear for them.
    $assignments->setAssignments($proposePolicy, $editor, []);
    reset_caches();

    $emptySources = $sourcesFor($editor);
    check(
        'a governed source with nothing in it is hidden',
        !in_array('section:' . $fixture->channel->uid, $emptySources, true),
        json_encode($emptySources),
    );

    check(
        'and the ungoverned one still is not',
        in_array('section:' . $fixture->structure->uid, $emptySources, true),
    );

    $assignments->assign($proposePolicy, $editor, [$fixture->entries['stranger']->id]);
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Explain');

    $report = $plugin->explain->explain($fixture->entries['stranger'], $editor);
    check('the explain report reaches a verdict per ability', count($report['verdicts']) === count(Ability::all()));
    check('the explain verdict matches enforcement', $report['verdicts'][Ability::SAVE]->isDenied());
    check('it names the policy that applied', $report['policies'] !== []);

    // By handle, not by position: the report lists every policy covering the element type,
    // including the ones that did not apply, and their order is the policies' own sort order.
    $proposeRow = null;

    foreach ($report['policies'] as $row) {
        if ($row['policy']->handle === 'secludePropose') {
            $proposeRow = $row;
        }
    }

    check('and marks it as applying', ($proposeRow['applies'] ?? false) === true, json_encode(array_map(
        static fn($r) => $r['policy']->handle . '=' . ($r['applies'] ? 'y' : 'n'),
        $report['policies'],
    )));

    // The per-grant breakdown must not disturb the policy's own verdict. `Explain` evaluates a
    // one-grant copy of the policy that carries the same ID, so a shared cache entry between the
    // two would show up right here.
    $beforeExplain = $authority->check($fixture->entries['stranger'], $editor, Ability::VIEW)->isAllowed();
    $plugin->explain->explain($fixture->entries['stranger'], $editor);
    $afterExplain = $authority->check($fixture->entries['stranger'], $editor, Ability::VIEW)->isAllowed();

    check('explaining a verdict does not change it', $beforeExplain === $afterExplain && $afterExplain === true);

    // And the grant rows themselves must be individually accurate, not all reporting the policy's
    // overall answer.
    $multi = policy([
        'name' => 'Seclude Multi',
        'handle' => 'secludeMulti',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AssignedGrant(), new AuthorGrant()],
        'abilities' => ['view' => true, 'save' => true],
    ]);

    $assignments->assign($multi, $editor, [$fixture->entries['stranger']->id]);
    reset_caches();

    $multiReport = $plugin->explain->explain($fixture->entries['stranger'], $editor);
    $multiRow = null;

    foreach ($multiReport['policies'] as $row) {
        if ($row['policy']->handle === 'secludeMulti') {
            $multiRow = $row;
        }
    }

    check(
        'each grant is reported on its own merits',
        ($multiRow['grants'][0]['matched'] ?? null) === true && ($multiRow['grants'][1]['matched'] ?? null) === false,
        json_encode(array_column($multiRow['grants'] ?? [], 'matched')),
    );

    $policies->deletePolicy($policies->getPolicyByHandle('secludeMulti'));
    reset_caches();

    $coverage = $plugin->explain->grantedIds($proposePolicy, $editor);
    check('coverage lists the granted element', $coverage === [(int)$fixture->entries['stranger']->id], json_encode($coverage));

    // -----------------------------------------------------------------------------------------
    heading('Unevaluable policies are not enforced');

    $broken = policy([
        'name' => 'Seclude Broken',
        'handle' => 'secludeBroken',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
    ]);

    // Point it at a section that does not exist. This is what a deleted section looks like.
    $broken->scope->sourceUids = ['00000000-0000-0000-0000-000000000000'];
    $policies->savePolicy($broken, false);
    reset_caches();

    $reloaded = $policies->getPolicyByHandle('secludeBroken');
    check('a policy naming a missing section is unevaluable', !$reloaded->isEvaluable());
    check('and is excluded from enforcement', !in_array('secludeBroken', array_map(fn($p) => $p->handle, $policies->getActivePolicies()), true));
    check('and is reported', in_array('secludeBroken', array_map(fn($p) => $p->handle, $policies->getUnevaluablePolicies()), true));

    $policies->deletePolicy($reloaded);
    reset_caches();

    // A non-admin with `deleteUsers` must not be able to lift a policy by deleting one person it
    // names. The missing user simply matches nobody.
    $ghost = policy([
        'name' => 'Seclude Ghost',
        'handle' => 'secludeGhost',
        'subjects' => [
            'userGroupUids' => [$fixture->group->uid],
            'userUids' => ['00000000-0000-0000-0000-000000000001'],
        ],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true],
    ]);

    check('a policy naming a deleted user stays evaluable', $ghost->isEvaluable(), implode(' ', $ghost->unevaluableReasons()));

    $policies->deletePolicy($ghost);
    reset_caches();

    // A condition whose rule cannot be rebuilt must match nothing, not everything.
    $lossy = policy([
        'name' => 'Seclude Lossy',
        'handle' => 'secludeLossy',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new ConditionGrant(['condition' => [
            'class' => craft\elements\conditions\entries\EntryCondition::class,
            'conditionRules' => [[
                'class' => craft\fields\conditions\TextFieldConditionRule::class,
                'fieldUid' => '00000000-0000-0000-0000-000000000002',
                'operator' => '=',
                'value' => 'x',
            ]],
        ]])],
        'abilities' => ['view' => true],
    ]);

    $lossyGrant = $lossy->getGrantsByType(ConditionGrant::type())[0] ?? null;
    $lossyQuery = $lossyGrant?->idQuery($editor, $lossy);
    check('a condition that lost a rule matches no element', $lossyGrant?->contains($fixture->entries['south'], $editor, $lossy) === false);
    check('and lists none', $lossyQuery !== null && $lossyQuery->column() === [], json_encode($lossyQuery?->column()));

    $policies->deletePolicy($lossy);
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Posted criteria cannot switch the filter off');

    // Craft's index controllers run posted `criteria` through Craft::configure().
    foreach (['seclude' => false, 'secludeFlag' => false] as $key => $value) {
        $refused = false;

        try {
            Craft::configure(Entry::find(), [$key => $value]);
        } catch (Throwable) {
            $refused = true;
        }

        check("criteria[$key] is refused", $refused);
    }

    // -----------------------------------------------------------------------------------------
    heading('Categories, assets and users');

    $categoryPolicy = policy([
        'name' => 'Seclude Categories',
        'handle' => 'secludeCategories',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_CATEGORIES, 'sourceUids' => [$fixture->categoryGroup->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
    ]);

    $assignments->assign($categoryPolicy, $editor, [$fixture->categories['Alpha']->id]);
    reset_caches();

    check('an assigned category is granted', $authority->check($fixture->categories['Alpha'], $editor, Ability::SAVE)->isAllowed());
    check('an unassigned one is refused', is_denied($authority->check($fixture->categories['Beta'], $editor, Ability::SAVE)));

    $userPolicy = policy([
        'name' => 'Seclude Users',
        'handle' => 'secludeUsers',
        'subjects' => ['userUids' => [$editor->uid]],
        'scope' => ['type' => TargetScope::TYPE_USERS, 'sourceUids' => [$fixture->group->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
    ]);

    reset_caches();

    $v = $authority->check($other, $editor, Ability::SAVE);
    check('a user element in a governed group is refused without a grant', is_denied($v), $v->reason);
    check('and Seclude still never authorises one', never_grants($v));

    $adminVerdict = $authority->check($admin, $editor, Ability::SAVE);
    check('an admin outside the governed group is untouched by the policy', $adminVerdict->isSilent(), $adminVerdict->reason);

    $policies->deletePolicy($policies->getPolicyByHandle('secludeUsers'));
    $policies->deletePolicy($policies->getPolicyByHandle('secludeCategories'));
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Delegation guard');

    // Two policies over the same section: one lets the editor see everything, the other hands out
    // editing and deleting by assignment. Seeing an entry must not be enough to pass on the right
    // to edit and delete it.
    $viewAll = policy([
        'name' => 'Seclude View All',
        'handle' => 'secludeViewAll',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new ConditionGrant(['condition' => [
            'class' => craft\elements\conditions\entries\EntryCondition::class,
            'conditionRules' => [],
        ]])],
        'abilities' => ['view' => true],
    ]);

    $editAssigned = policy([
        'name' => 'Seclude Edit Assigned',
        'handle' => 'secludeEditAssigned',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'delete' => true],
    ]);

    Craft::$app->getUser()->setIdentity($editor);
    reset_caches();

    check(
        'an admin may delegate anything',
        $assignments->canDelegate($admin, $fixture->entries['south'], $editAssigned),
    );

    check(
        'a delegator may hand over what they can see under a view-only policy',
        $assignments->canDelegate($editor, $fixture->entries['south'], $viewAll),
    );

    check(
        'but not under a policy that grants more than they hold themselves',
        !$assignments->canDelegate($editor, $fixture->entries['south'], $editAssigned),
    );

    $policies->deletePolicy($policies->getPolicyByHandle('secludeViewAll'));
    reset_caches();

    check(
        'a secluded delegator may not hand over what they cannot reach',
        !$assignments->canDelegate($editor, $fixture->entries['south'], $editAssigned),
    );

    check('a non-admin may not manage their own assignments', !$assignments->canAssignTo($editor, $editor));
    check('but may manage somebody else’s', $assignments->canAssignTo($editor, $other));
    check('an admin may manage their own', $assignments->canAssignTo($admin, $admin));

    $policies->deletePolicy($policies->getPolicyByHandle('secludeEditAssigned'));
    Craft::$app->getUser()->setIdentity(null);
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('The invariant');

    $everything = [];

    foreach ([$fixture->entries['stranger'], $fixture->entries['authored'], $fixture->entries['north'], $fixture->categories['Alpha']] as $element) {
        foreach ([$editor, $other, $admin] as $who) {
            foreach (Ability::all() as $ability) {
                $everything[] = $authority->check($element, $who, $ability);
            }
        }
    }

    check(
        'no verdict in ' . count($everything) . ' ever authorises',
        array_reduce($everything, static fn(bool $carry, Verdict $v) => $carry && never_grants($v), true),
    );

    // -----------------------------------------------------------------------------------------
    heading('Nested entries defer to their owner');

    $nestedPolicy = policy([
        'name' => 'Seclude Nested',
        'handle' => 'secludeNested',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
    ]);

    reset_caches();

    $nested = Entry::find()->id($fixture->nested->id)->status(null)->seclude(false)->one();
    check('the nested entry loaded', $nested !== null);

    $subject = $authority->subject($nested);
    check(
        'a nested entry is judged as the entry that owns it',
        (int)$subject->id === (int)$fixture->entries['stranger']->id,
        'got ' . (string)$subject->id,
    );

    $v = $authority->check($nested, $editor, Ability::SAVE);
    check('with the owner ungranted, the block is refused', is_denied($v), $v->reason);

    $assignments->assign($nestedPolicy, $editor, [$fixture->entries['stranger']->id]);
    reset_caches();

    $v = $authority->check($nested, $editor, Ability::SAVE);
    check('granting the owner grants the block inside it', $v->isAllowed(), $v->reason);

    check(
        'a nested entry is outside every scope on its own',
        !$nestedPolicy->scope->contains($nested),
    );

    // -----------------------------------------------------------------------------------------
    heading('Craft’s authorization events');

    // The real path: Craft asking, Seclude answering through Guard.
    Craft::$app->getUser()->setIdentity($editor);
    reset_caches();

    // The group holds real Craft permissions on this section, so Craft alone would say yes here.
    // Anything refused below is Seclude narrowing, which is the only way to test that it works.
    check(
        'canSave() is refused for an ungranted element',
        Craft::$app->getElements()->canSave($fixture->entries['south'], $editor) === false,
    );

    check(
        'canView() is refused too',
        Craft::$app->getElements()->canView($fixture->entries['south'], $editor) === false,
    );

    check(
        'and permitted for a granted one — Seclude does not over-block',
        Craft::$app->getElements()->canSave($fixture->entries['stranger'], $editor) === true,
    );

    // The other half of the invariant. The group has no Craft permission on the structure section,
    // so a Seclude grant there must change nothing: Seclude narrows, it never widens.
    $widenPolicy = policy([
        'name' => 'Seclude Widen',
        'handle' => 'secludeWiden',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->structure->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'create' => true, 'delete' => true, 'duplicate' => true, 'propose' => true],
    ]);

    $assignments->assign($widenPolicy, $editor, [$fixture->entries['root']->id]);
    reset_caches();

    check(
        'Seclude permits the grant',
        $authority->check($fixture->entries['root'], $editor, Ability::SAVE)->isAllowed(),
    );

    check(
        'but a grant cannot create a Craft permission',
        Craft::$app->getElements()->canSave($fixture->entries['root'], $editor) === false,
    );

    $policies->deletePolicy($policies->getPolicyByHandle('secludeWiden'));
    reset_caches();

    check(
        'an admin is unaffected by the same policy',
        Craft::$app->getElements()->canSave($fixture->entries['south'], $admin) === true,
    );

    check(
        'an ungoverned section still answers Craft’s way',
        Craft::$app->getElements()->canSave($fixture->entries['root'], $admin) === true,
    );

    Craft::$app->getUser()->setIdentity(null);
    $policies->deletePolicy($policies->getPolicyByHandle('secludeNested'));
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Adoption');

    $createPolicy = policy([
        'name' => 'Seclude Create',
        'handle' => 'secludeCreate',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'create' => true, 'propose' => true],
    ]);

    Craft::$app->getUser()->setIdentity($editor);
    reset_caches();

    check('creating is permitted by the policy', $authority->checkCreate(Entry::class, $fixture->entries['stranger'], $editor)->isAllowed());

    $fresh = new Entry([
        'sectionId' => $fixture->channel->id,
        'typeId' => $fixture->channelType->id,
        'title' => 'Seclude Just Created',
    ]);

    Craft::$app->getElements()->saveElement($fresh, false);
    $fixture->entries['created'] = $fresh;

    check(
        'a newly created element is assigned to its creator',
        in_array((int)$fresh->id, $assignments->assignedElementIds($createPolicy, $editor), true),
    );

    $v = $authority->check($fresh, $editor, Ability::SAVE);
    check('so they can open what they just made', $v->isAllowed(), $v->reason);

    $v = $authority->check($fresh, $other, Ability::SAVE);
    check('and nobody else gets it', is_denied($v), $v->reason);

    // The real "New entry" flow: an unpublished draft first, published afterwards. Craft asks
    // canSaveCanonical() about a clone with draftId cleared, which looks like an existing entry
    // that nobody has been granted.
    $draft = new Entry([
        'sectionId' => $fixture->channel->id,
        'typeId' => $fixture->channelType->id,
        'title' => 'Seclude Drafted',
    ]);
    Craft::$app->getDrafts()->saveElementAsDraft($draft, $editor->id, null, null, false);
    reset_caches();

    check('a new entry’s draft can be published by its creator', Craft::$app->getElements()->canSaveCanonical($draft, $editor));

    $published = Craft::$app->getDrafts()->applyDraft($draft);
    $fixture->entries['drafted'] = $published;
    reset_caches();

    check(
        'and once published it is theirs',
        in_array((int)$published->id, $assignments->assignedElementIds($createPolicy, $editor), true),
    );

    // A resave is not a creation, even inside the editor's request — this is what a queued
    // ResaveElements job running in their browser looks like.
    $south = Entry::find()->id($fixture->entries['south']->id)->status(null)->seclude(false)->one();
    $south->resaving = true;
    Craft::$app->getElements()->saveElement($south, false);
    reset_caches();

    check(
        'resaving an ungranted element does not adopt it',
        !in_array((int)$south->id, $assignments->assignedElementIds($createPolicy, $editor), true),
    );

    Craft::$app->getUser()->setIdentity(null);
    $policies->deletePolicy($policies->getPolicyByHandle('secludeCreate'));
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('The backstop');

    // Craft endpoints that never raise an authorization event — move-to-section, structure moves,
    // the asset and user controllers — still end in a save, a delete or a move.
    $backstopPolicy = policy([
        'name' => 'Seclude Backstop',
        'handle' => 'secludeBackstop',
        'subjects' => ['userGroupUids' => [$fixture->group->uid]],
        'scope' => ['type' => TargetScope::TYPE_ENTRIES, 'sourceUids' => [$fixture->channel->uid, $fixture->structure->uid]],
        'grants' => [new AssignedGrant()],
        'abilities' => ['view' => true, 'save' => true, 'propose' => true],
        'includeDescendants' => true,
    ]);

    $assignments->assign($backstopPolicy, $editor, [$fixture->entries['root']->id, $fixture->entries['stranger']->id]);
    Craft::$app->getUser()->setIdentity($editor);
    reset_caches();

    $south = Entry::find()->id($fixture->entries['south']->id)->status(null)->seclude(false)->one();
    check('saving an ungranted element is refused', Craft::$app->getElements()->saveElement($south, false) === false);
    check('deleting one is refused', Craft::$app->getElements()->deleteElement($south) === false);

    $stranger = Entry::find()->id($fixture->entries['stranger']->id)->status(null)->seclude(false)->one();
    check('saving a granted one is not', Craft::$app->getElements()->saveElement($stranger, false) === true);

    $refusedMove = false;

    try {
        Craft::$app->getEntries()->moveEntryToSection($south, $fixture->structure);
    } catch (yii\web\ForbiddenHttpException) {
        $refusedMove = true;
    }

    check('moving an ungranted entry to another section is refused', $refusedMove);

    $structures = Craft::$app->getStructures();
    $sibling = Entry::find()->id($fixture->entries['sibling']->id)->status(null)->seclude(false)->one();
    $root = Entry::find()->id($fixture->entries['root']->id)->status(null)->seclude(false)->one();
    check(
        'moving an ungranted entry under a granted branch is refused',
        $structures->append($fixture->structure->structureId, $sibling, $root) === false,
    );

    $child = Entry::find()->id($fixture->entries['child']->id)->status(null)->seclude(false)->one();
    check(
        'moving a granted one is not',
        $structures->appendToRoot($fixture->structure->structureId, $child) === true,
    );

    Craft::$app->getUser()->setIdentity($admin);
    reset_caches();
    check('an admin is not held back', Craft::$app->getElements()->saveElement($south, false) === true);

    Craft::$app->getUser()->setIdentity(null);
    $policies->deletePolicy($policies->getPolicyByHandle('secludeBackstop'));
    reset_caches();

    // -----------------------------------------------------------------------------------------
    heading('Assignment housekeeping');

    $before = count($assignments->assignedElementIds($proposePolicy, $editor));
    $assignments->setAssignments($proposePolicy, $editor, []);
    check('setting an empty list clears assignments', $before === 1 && $assignments->assignedElementIds($proposePolicy, $editor) === []);

    $assignments->assign($proposePolicy, $editor, [$fixture->entries['stranger']->id]);
    $again = $assignments->assign($proposePolicy, $editor, [$fixture->entries['stranger']->id]);
    check('assigning twice is a no-op', $again === 0);
} catch (Throwable $e) {
    $failed++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    echo "\nTearing down…\n";

    // A run that died mid-check may have left a secluded identity set, and the backstop would then
    // refuse the teardown's own deletes.
    Craft::$app->getUser()->setIdentity(null);

    foreach ($madePolicies as $policy) {
        $current = $policies->getPolicyByHandle($policy->handle);

        if ($current !== null) {
            $policies->deletePolicy($current);
        }
    }

    try {
        $fixture->tearDown();
    } catch (Throwable $e) {
        echo "\033[33mTeardown warning: " . $e->getMessage() . "\033[0m\n";
    }
}

echo "\n";
echo $failed === 0
    ? "\033[32m$passed passed, 0 failed\033[0m\n"
    : "\033[31m$passed passed, $failed failed\033[0m\n";

exit($failed === 0 ? 0 : 1);
