<?php

declare(strict_types=1);

namespace justinholtweb\seclude\grants;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\User;
use justinholtweb\seclude\models\Policy;

/**
 * One answer to "which elements inside this policy's scope does this user get".
 *
 * A grant's whole job is {@see self::idQuery()}: a query selecting a single `id` column. That one
 * method drives both halves of the plugin — narrowing a CP listing, and judging a single element
 * on its edit page — so a grant cannot show somebody a row in an index and then refuse them when
 * they click it. {@see self::contains()} exists only for grants that can answer faster than the
 * query can, and the integration checks assert the two agree.
 */
interface GrantInterface
{
    public static function type(): string;

    /** A short label for the CP. */
    public static function displayName(): string;

    /**
     * A query selecting one `id` column: the element IDs this grant hands the user.
     *
     * Null means the grant cannot apply here at all (wrong element type, deleted field), which is
     * different from "applies and matches nothing".
     */
    public function idQuery(User $user, Policy $policy): ?Query;

    /**
     * A direct answer for one element, when the grant has a cheaper way to know.
     *
     * Null means "no shortcut, run the query". Returning a value here is an optimisation and a
     * promise: it must agree with {@see self::idQuery()} for every element in scope.
     */
    public function contains(ElementInterface $element, User $user, Policy $policy): ?bool;

    /** Whether everything this grant names still exists. A false disables the whole policy. */
    public function isResolvable(Policy $policy): bool;

    /** Why it is not resolvable, for the CP banner. */
    public function unresolvableReason(Policy $policy): ?string;

    /** Whether this grant reads {{%seclude_assignments}}, and so needs the assignment screens. */
    public function usesAssignments(): bool;

    public function describe(Policy $policy): string;

    public function getConfig(): array;
}
