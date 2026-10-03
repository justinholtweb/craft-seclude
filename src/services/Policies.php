<?php

declare(strict_types=1);

namespace justinholtweb\seclude\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use justinholtweb\seclude\grants\AssignedGrant;
use justinholtweb\seclude\grants\AuthorGrant;
use justinholtweb\seclude\grants\ConditionGrant;
use justinholtweb\seclude\grants\GrantInterface;
use justinholtweb\seclude\grants\RelationGrant;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\Plugin;
use justinholtweb\seclude\records\PolicyRecord;
use Throwable;

/**
 * Storing and reading policies.
 *
 * Project config is the source of truth; `{{%seclude_policies}}` is a mirror written only by the
 * config handlers, so a policy written on a laptop arrives in production with the deploy that
 * carries the section it governs.
 */
class Policies extends Component
{
    public const CONFIG_POLICIES_KEY = 'seclude.policies';

    /** @var Policy[]|null */
    private ?array $_policies = null;

    /** @var array<string, class-string<GrantInterface>> */
    private array $_grantTypes = [
        'assigned' => AssignedGrant::class,
        'author' => AuthorGrant::class,
        'relation' => RelationGrant::class,
        'condition' => ConditionGrant::class,
    ];

    /** @return array<string, class-string<GrantInterface>> */
    public function getGrantTypes(): array
    {
        return $this->_grantTypes;
    }

    public function createGrant(array $config): ?GrantInterface
    {
        $type = $config['type'] ?? null;
        $class = $type !== null ? ($this->_grantTypes[$type] ?? null) : null;

        if ($class === null) {
            return null;
        }

        unset($config['type']);

        return new $class($config);
    }

    /** @return Policy[] */
    public function getAllPolicies(): array
    {
        if ($this->_policies === null) {
            $this->_policies = [];

            $rows = (new Query())
                ->select(['id', 'name', 'handle', 'enabled', 'sortOrder', 'scopeType', 'settings', 'uid'])
                ->from(['{{%seclude_policies}}'])
                ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                ->all();

            foreach ($rows as $row) {
                $this->_policies[] = $this->createPolicyFromRow($row);
            }
        }

        return $this->_policies;
    }

    /**
     * The policies Seclude will actually enforce.
     *
     * Enabled *and* evaluable. An enabled policy whose section was deleted is dropped here rather
     * than denying everything in a scope that no longer resolves — the reasoning is in
     * `docs/plan.md`, and {@see self::getUnevaluablePolicies()} makes sure it is not silent.
     *
     * @return Policy[]
     */
    public function getActivePolicies(): array
    {
        return array_values(array_filter(
            $this->getAllPolicies(),
            static fn(Policy $p) => $p->enabled && $p->isEvaluable(),
        ));
    }

    /** @return Policy[] */
    public function getUnevaluablePolicies(): array
    {
        return array_values(array_filter(
            $this->getAllPolicies(),
            static fn(Policy $p) => $p->enabled && !$p->isEvaluable(),
        ));
    }

    /**
     * Active policies that could govern the given element class.
     *
     * The hot path — every element query and every authorization check lands here — so it filters
     * the in-memory list rather than the database.
     *
     * @return Policy[]
     */
    public function getPoliciesForElementType(string $elementType): array
    {
        return array_values(array_filter(
            $this->getActivePolicies(),
            static fn(Policy $p) => $p->scope->coversElementType($elementType),
        ));
    }

    public function getPolicyById(?int $id): ?Policy
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->id === $id) {
                return $policy;
            }
        }

        return null;
    }

    public function getPolicyByHandle(string $handle): ?Policy
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->handle === $handle) {
                return $policy;
            }
        }

        return null;
    }

    public function getPolicyByUid(string $uid): ?Policy
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->uid === $uid) {
                return $policy;
            }
        }

        return null;
    }

    public function savePolicy(Policy $policy, bool $runValidation = true): bool
    {
        $isNew = $policy->id === null;

        if ($isNew) {
            $policy->uid = StringHelper::UUID();
        } elseif ($policy->uid === null) {
            $policy->uid = Db::uidById('{{%seclude_policies}}', $policy->id);
        }

        if ($isNew && $policy->sortOrder === 0) {
            $policy->sortOrder = count($this->getAllPolicies()) + 1;
        }

        // Repair before validating, so "save without view" is corrected rather than rejected.
        $policy->abilities->normalize();

        if ($runValidation && !$policy->validate()) {
            Craft::info('Policy not saved due to validation error.', Plugin::LOG_CATEGORY);

            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_POLICIES_KEY . '.' . $policy->uid,
            $policy->getConfig(),
            "Save the “{$policy->handle}” Seclude policy",
        );

        if ($isNew) {
            $policy->id = Db::idByUid('{{%seclude_policies}}', $policy->uid);
        }

        $this->invalidate();

        return true;
    }

    public function reorderPolicies(array $uids): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach (array_values($uids) as $index => $uid) {
            // Posted straight from the CP. Anything but a UID would be a project-config *path* —
            // `uid.name` reaches inside a policy rather than naming one.
            if (!is_string($uid) || !StringHelper::isUUID($uid)) {
                continue;
            }

            $config = $projectConfig->get(self::CONFIG_POLICIES_KEY . '.' . $uid);

            if ($config === null) {
                continue;
            }

            $config['sortOrder'] = $index + 1;
            $projectConfig->set(self::CONFIG_POLICIES_KEY . '.' . $uid, $config, 'Reorder Seclude policies');
        }

        $this->invalidate();

        return true;
    }

    public function setEnabled(Policy $policy, bool $enabled): bool
    {
        $policy->enabled = $enabled;

        return $this->savePolicy($policy, false);
    }

    /**
     * Turn every policy off.
     *
     * The escape hatch, exposed as `seclude/policies/panic`. A permissions plugin needs a way back
     * that does not require getting into the control panel it may be blocking.
     */
    public function disableAll(): int
    {
        $count = 0;

        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->enabled && $this->setEnabled($policy, false)) {
                $count++;
            }
        }

        return $count;
    }

    public function deletePolicyById(int $id): bool
    {
        $policy = $this->getPolicyById($id);

        return $policy !== null && $this->deletePolicy($policy);
    }

    public function deletePolicy(Policy $policy): bool
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_POLICIES_KEY . '.' . $policy->uid,
            "Delete the “{$policy->handle}” Seclude policy",
        );

        // Project config coalesces an add and a remove inside one request, so the remove handler
        // may never fire — see `[[craft-plugin-gotchas]]`. Both paths are idempotent.
        $this->deletePolicyRecord($policy->uid);

        $this->invalidate();

        return true;
    }

    /**
     * Forget the policy list, and every decision made from it.
     *
     * A policy edit changes what is granted, so the memoized verdicts and grant lookups behind it
     * are wrong from that moment. Within one request — a CP save followed by a redirect, a console
     * command looping over policies — keeping them would silently enforce the old policy.
     */
    private function invalidate(): void
    {
        $this->_policies = null;

        Plugin::getInstance()->authority->flush();
    }

    // Project config handlers
    // ---------------------------------------------------------------------------------------

    public function handleChangedPolicy(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        // Sections, category groups, volumes and user groups are all referenced by UID in a
        // policy, so they have to exist before the mirror row is written or a fresh
        // `project-config/apply` orders them arbitrarily.
        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllUserGroupsProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();
        ProjectConfigHelper::ensureAllSectionsProcessed();
        ProjectConfigHelper::ensureAllEntryTypesProcessed();

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record = PolicyRecord::findOne(['uid' => $uid]) ?? new PolicyRecord();

            $record->uid = $uid;
            $record->name = (string)($data['name'] ?? '');
            $record->handle = (string)($data['handle'] ?? '');
            $record->enabled = (bool)($data['enabled'] ?? true);
            $record->sortOrder = (int)($data['sortOrder'] ?? 0);
            $record->scopeType = (string)($data['scope']['type'] ?? 'entries');
            $record->settings = Json::encode($data);

            $record->save(false);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->invalidate();
    }

    public function handleDeletedPolicy(ConfigEvent $event): void
    {
        $this->deletePolicyRecord($event->tokenMatches[0]);
        $this->invalidate();
    }

    private function deletePolicyRecord(?string $uid): void
    {
        if ($uid === null) {
            return;
        }

        PolicyRecord::findOne(['uid' => $uid])?->delete();
    }

    public function rebuildProjectConfig(): array
    {
        $config = [];

        foreach ($this->getAllPolicies() as $policy) {
            $config[$policy->uid] = $policy->getConfig();
        }

        return $config;
    }

    private function createPolicyFromRow(array $row): Policy
    {
        $settings = $row['settings'] ? Json::decodeIfJson($row['settings']) : [];
        $settings = is_array($settings) ? $settings : [];

        return new Policy($settings + [
            'id' => (int)$row['id'],
            'uid' => $row['uid'],
            'name' => $row['name'],
            'handle' => $row['handle'],
            'enabled' => (bool)$row['enabled'],
            'sortOrder' => (int)$row['sortOrder'],
        ]);
    }
}
