<?php

declare(strict_types=1);

namespace justinholtweb\seclude\grants;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\User;
use justinholtweb\seclude\models\Policy;
use justinholtweb\seclude\Plugin;
use Throwable;

/**
 * Elements matching a condition built with Craft's own condition builder.
 *
 * The escape hatch: anything the other grants cannot express — a status, a date, a custom field
 * value, a plugin's own condition rule — can be said here, in the UI editors already know from
 * entry index filters and dynamic entry conditions.
 *
 * The condition is the same for everybody the policy names. It answers "which elements", not
 * "which elements for this user"; use {@see RelationGrant} for the per-user half. The two compose:
 * a policy can grant *entries in the Midwest* (condition) *that relate to me* (relation).
 */
class ConditionGrant extends BaseGrant
{
    /** A serialized `craft\elements\conditions\ElementCondition` config. */
    public array $condition = [];

    private ?array $_ids = null;

    public static function type(): string
    {
        return 'condition';
    }

    public static function displayName(): string
    {
        return Craft::t('seclude', 'Elements matching a condition');
    }

    /**
     * The one grant that materialises IDs instead of handing back a subquery.
     *
     * An element condition modifies an `ElementQuery`, and an `ElementQuery` is not safely
     * embeddable as a subquery — it assembles itself at execution time. So this runs the query and
     * caches the result for the request. {@see self::contains()} exists precisely so that the
     * common case, judging one element on its edit page, never pays for it.
     */
    public function idQuery(User $user, Policy $policy): ?Query
    {
        $condition = $this->resolveCondition($policy);

        if ($condition === null) {
            return null;
        }

        if ($this->isDegraded($condition)) {
            return (new Query())
                ->select(['id' => 'seclude_ci.id'])
                ->from(['seclude_ci' => $this->idRows([])]);
        }

        if ($this->_ids === null) {
            $elementType = $policy->scope->elementType();
            /** @var class-string<ElementInterface> $elementType */
            $query = $elementType::find()->status(null)->siteId('*')->unique();

            // Seclude's own lookups must never be filtered by Seclude, or a policy that restricts
            // a section would hide the very elements it is trying to grant.
            $query->seclude(false);

            $condition->modifyQuery($query);

            $this->_ids = array_map('intval', $query->ids());
        }

        return (new Query())
            ->select(['id' => 'seclude_ci.id'])
            ->from(['seclude_ci' => $this->idRows($this->_ids)]);
    }

    /**
     * The cheap path: ask the condition about one element directly.
     *
     * `matchElement()` and `modifyQuery()` are Craft's own two halves of a condition and are
     * expected to agree; `tests/integration/checks.php` asserts they do for the conditions
     * Seclude builds, because a disagreement here shows up as an element visible in a listing
     * that refuses to open.
     */
    public function contains(ElementInterface $element, User $user, Policy $policy): ?bool
    {
        $condition = $this->resolveCondition($policy);

        if ($condition === null) {
            return null;
        }

        if ($this->isDegraded($condition)) {
            return false;
        }

        try {
            return $condition->matchElement($element);
        } catch (Throwable $e) {
            // A rule referencing a field that has since changed shape can throw. Fall back to the
            // query rather than guessing — this is a permission decision.
            Craft::warning(
                sprintf('Condition grant could not match element %d: %s', (int)$element->id, $e->getMessage()),
                Plugin::LOG_CATEGORY,
            );

            return null;
        }
    }

    public function unresolvableReason(Policy $policy): ?string
    {
        if ($this->condition === []) {
            return Craft::t('seclude', 'This grant has no condition set.');
        }

        return $this->resolveCondition($policy) === null
            ? Craft::t('seclude', 'The condition could not be rebuilt — a field or rule it uses may have been deleted.')
            : null;
    }

    public function describe(Policy $policy): string
    {
        $condition = $this->resolveCondition($policy);

        if ($condition !== null && $this->isDegraded($condition)) {
            return Craft::t('seclude', 'nothing — a condition rule refers to a field or rule that no longer exists');
        }

        $count = $condition !== null ? count($condition->getConditionRules()) : 0;

        // A condition with no rules matches everything, which is a legitimate way to say "the whole
        // scope" — and a very easy thing to leave behind by accident. Saying "0 rules" would let it
        // read as "grants nothing", which is the opposite of what it does.
        if ($count === 0) {
            return Craft::t('seclude', 'every element in the scope (the condition has no rules)');
        }

        return Craft::t('seclude', 'elements matching {n, plural, =1{one rule} other{# rules}}', ['n' => $count]);
    }

    public function getConfig(): array
    {
        return [
            'type' => self::type(),
            'condition' => $this->condition,
        ];
    }

    /**
     * Whether the condition has quietly stopped restricting anything it was written to.
     *
     * Two ways that happens, and Craft reports neither. Rebuilding can drop a rule outright — a
     * rule class from an uninstalled plugin. Or it keeps the rule but the rule's field is gone, and
     * Craft's field rules then deliberately match *every* element (`matchElement()` returns true
     * for a missing field). Every rule is a further restriction, so either way the condition
     * matches more than it was written to, up to the whole scope. A grant in that state matches
     * nothing instead: the policy still applies, so this refuses elements inside it rather than
     * switching the policy off.
     */
    private function isDegraded(ElementConditionInterface $condition): bool
    {
        $lost = $this->configuredRuleCount() - count($condition->getConditionRules());

        foreach ($condition->getConditionRules() as $rule) {
            // A field rule whose field has gone cannot even name itself: `getLabel()` throws.
            try {
                $rule->getLabel();
            } catch (Throwable) {
                $lost++;
            }
        }

        if ($lost > 0) {
            Craft::warning(sprintf('Condition grant has %d unusable rule(s); matching nothing.', $lost), Plugin::LOG_CATEGORY);
        }

        return $lost > 0;
    }

    private function configuredRuleCount(): int
    {
        $rules = $this->condition['conditionRules'] ?? [];

        return is_array($rules) ? count($rules) : 0;
    }

    private function resolveCondition(Policy $policy): ?ElementConditionInterface
    {
        if ($this->condition === []) {
            return null;
        }

        $elementType = $policy->scope->elementType();

        if ($elementType === null) {
            return null;
        }

        try {
            $config = $this->condition;
            $config['class'] ??= $elementType::createCondition()::class;
            $config['elementType'] = $elementType;

            $condition = Craft::$app->getConditions()->createCondition($config);

            return $condition instanceof ElementConditionInterface ? $condition : null;
        } catch (Throwable $e) {
            Craft::warning('Could not rebuild condition grant: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /**
     * An id list as a derived table, so the caller still gets a `Query` like every other grant.
     *
     * `SELECT 0 WHERE 1=0` for the empty case: a `VALUES` list with no rows is a syntax error, and
     * returning null instead would read as "this grant does not apply" rather than "it applies and
     * matches nothing" — the difference between denying and deferring.
     */
    private function idRows(array $ids): Query
    {
        if ($ids === []) {
            return (new Query())->select(['id' => new \yii\db\Expression('0')])->where('1=0');
        }

        return (new Query())
            ->select(['id' => 'seclude_cid.id'])
            ->from(['seclude_cid' => '{{%elements}}'])
            ->where(['seclude_cid.id' => $ids]);
    }
}
