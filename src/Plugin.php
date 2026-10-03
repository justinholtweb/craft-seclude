<?php

declare(strict_types=1);

namespace justinholtweb\seclude;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\db\Query as DbQuery;
use craft\elements\db\ElementQuery;
use craft\events\DefineBehaviorsEvent;
use craft\events\ElementEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\seclude\behaviors\SecludeQueryBehavior;
use justinholtweb\seclude\models\Settings;
use justinholtweb\seclude\services\Adoption;
use justinholtweb\seclude\services\Assignments;
use justinholtweb\seclude\services\Authority;
use justinholtweb\seclude\services\Backstop;
use justinholtweb\seclude\services\Explain;
use justinholtweb\seclude\services\Guard;
use justinholtweb\seclude\services\Policies;
use justinholtweb\seclude\services\QueryFilter;
use justinholtweb\seclude\services\Refusals;
use justinholtweb\seclude\services\Resolver;
use justinholtweb\seclude\services\Sources;
use justinholtweb\seclude\twig\SecludeVariable;
use yii\base\Event;

/**
 * Seclude — per-element user restrictions for Craft CMS.
 *
 * Craft's content permissions stop at the container: a section, a category group, a volume, a user
 * group. Seclude carries them the rest of the way, down to the individual element, by hand-picked
 * assignment or by rules that follow authorship, structure, relations and conditions.
 *
 * @property-read Policies $policies
 * @property-read Authority $authority
 * @property-read Resolver $resolver
 * @property-read Assignments $assignments
 * @property-read Guard $guard
 * @property-read Backstop $backstop
 * @property-read QueryFilter $queryFilter
 * @property-read Sources $sources
 * @property-read Adoption $adoption
 * @property-read Refusals $refusals
 * @property-read Explain $explain
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /**
     * Users holding this are never secluded.
     *
     * Exists so an agency's support account can be exempted without being made an admin — the
     * alternative being that somebody turns `secludeAdmins` off for one person and hands out the
     * keys to everything.
     */
    public const PERMISSION_BYPASS = 'seclude:bypass';

    /**
     * Who may hand elements to other people.
     *
     * Separate from being an admin so a department head can delegate their own work.
     * {@see Assignments::canDelegate()} keeps that honest: a non-admin may only assign elements
     * they can reach themselves.
     */
    public const PERMISSION_ASSIGN = 'seclude:manageAssignments';

    public const LOG_CATEGORY = 'seclude';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'policies' => Policies::class,
                'authority' => Authority::class,
                'resolver' => Resolver::class,
                'assignments' => Assignments::class,
                'guard' => Guard::class,
                'backstop' => Backstop::class,
                'queryFilter' => QueryFilter::class,
                'sources' => Sources::class,
                'adoption' => Adoption::class,
                'refusals' => Refusals::class,
                'explain' => Explain::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerProjectConfig();
        $this->registerPermissions();
        $this->registerRoutes();
        $this->registerTwig();
        $this->registerQueryBehavior();
        $this->registerGarbageCollection();

        // Everything below enforces something, and none of it should run before Craft has finished
        // booting: policies live in project config, and reading project config during `init()` on a
        // half-installed site is how a plugin makes `craft install` fail.
        Craft::$app->onInit(function() {
            $this->guard->register();
            $this->backstop->register();
            $this->sources->register();
            $this->registerQueryFilter();
            $this->registerAdoption();
        });
    }

    public function getCpNavItem(): ?array
    {
        // Every screen is for admins or assigners. Anyone else holding `accessPlugin-seclude`
        // would only find a nav item that leads to a 403.
        if (!$this->canAssign()) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('seclude', 'Seclude');

        $isAdmin = Craft::$app->getUser()->getIsAdmin();

        if ($isAdmin) {
            $item['subnav']['policies'] = [
                'label' => Craft::t('seclude', 'Policies'),
                'url' => 'seclude/policies',
            ];
        } else {
            // `seclude` routes to the policies index, which is admin-only.
            $item['url'] = 'seclude/assignments';
        }

        $item['subnav']['assignments'] = [
            'label' => Craft::t('seclude', 'Assignments'),
            'url' => 'seclude/assignments',
        ];

        $item['subnav']['explain'] = [
            'label' => Craft::t('seclude', 'Who can edit what'),
            'url' => 'seclude/explain',
        ];

        if ($isAdmin) {
            if ($this->getSettings()->logRefusals) {
                $item['subnav']['refusals'] = [
                    'label' => Craft::t('seclude', 'Refusals'),
                    'url' => 'seclude/refusals',
                ];
            }

            $item['subnav']['settings'] = [
                'label' => Craft::t('seclude', 'Settings'),
                'url' => 'settings/plugins/seclude',
            ];
        }

        return $item;
    }

    public function canAssign(): bool
    {
        $user = Craft::$app->getUser();

        return $user->getIsAdmin() || $user->checkPermission(self::PERMISSION_ASSIGN);
    }

    /**
     * Take the policies out of project config on the way out.
     *
     * Craft only clears `plugins.seclude`; Seclude's policies live at a top-level `seclude` key it
     * knows nothing about. Without this the key outlives the plugin, turns up in every
     * `project-config/diff` from then on, and reinstalling silently resurrects the old policies —
     * which for a permissions plugin means restrictions nobody remembers writing, applied to
     * content that has moved on. (Family lesson, see `[[project_craft_redpen]]`.)
     */
    public function afterUninstall(): void
    {
        parent::afterUninstall();

        Craft::$app->getProjectConfig()->remove(
            Policies::CONFIG_POLICIES_KEY,
            'Remove Seclude’s policies',
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('seclude/_settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'unevaluable' => $this->policies->getUnevaluablePolicies(),
        ]);
    }

    // Registration
    // ---------------------------------------------------------------------------------------

    private function registerProjectConfig(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd(Policies::CONFIG_POLICIES_KEY . '.{uid}', [$this->policies, 'handleChangedPolicy'])
            ->onUpdate(Policies::CONFIG_POLICIES_KEY . '.{uid}', [$this->policies, 'handleChangedPolicy'])
            ->onRemove(Policies::CONFIG_POLICIES_KEY . '.{uid}', [$this->policies, 'handleDeletedPolicy']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            $event->config['seclude']['policies'] = $this->policies->rebuildProjectConfig();
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('seclude', 'Seclude'),
                'permissions' => [
                    self::PERMISSION_BYPASS => [
                        'label' => Craft::t('seclude', 'Bypass all Seclude policies'),
                    ],
                    self::PERMISSION_ASSIGN => [
                        'label' => Craft::t('seclude', 'Assign elements to other users'),
                        'info' => Craft::t('seclude', 'Non-admins can only assign elements they can reach themselves.'),
                    ],
                ],
            ];
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'seclude' => 'seclude/policies/index',
                'seclude/policies' => 'seclude/policies/index',
                'seclude/policies/new' => 'seclude/policies/edit',
                'seclude/policies/<policyId:\d+>' => 'seclude/policies/edit',
                'seclude/assignments' => 'seclude/assignments/index',
                'seclude/assignments/<policyId:\d+>/<userId:\d+>' => 'seclude/assignments/edit',
                'seclude/explain' => 'seclude/explain/index',
                'seclude/refusals' => 'seclude/refusals/index',
                'seclude/refusals/clear' => 'seclude/refusals/clear',
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->attachBehavior('seclude', SecludeVariable::class);
        });
    }

    /**
     * Registered outside `onInit()` on purpose.
     *
     * The behaviour has to be attached before any element query is *constructed*, and Craft builds
     * plenty of them while booting. Attaching it late means `.seclude(false)` throws an unknown
     * method error on exactly the queries Seclude most needs to exempt.
     */
    private function registerQueryBehavior(): void
    {
        Event::on(ElementQuery::class, DbQuery::EVENT_DEFINE_BEHAVIORS, static function(DefineBehaviorsEvent $event) {
            $event->behaviors['seclude'] = SecludeQueryBehavior::class;
        });
    }

    private function registerQueryFilter(): void
    {
        Event::on(ElementQuery::class, ElementQuery::EVENT_BEFORE_PREPARE, function(Event $event) {
            /** @var ElementQuery $query */
            $query = $event->sender;
            $this->queryFilter->handleBeforePrepare($query);
        });
    }

    private function registerAdoption(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $this->adoption->handleAfterSave($event);
        });
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->assignments->prune();
            $this->refusals->prune();
        });
    }
}
