<?php

declare(strict_types=1);

namespace justinholtweb\seclude\behaviors;

use craft\elements\db\ElementQuery;
use yii\base\Behavior;

/**
 * `.seclude(false)` — leave this query alone. `.seclude(true)` — filter it even outside an index.
 *
 * Seclude's own lookups need the first: a policy that restricts a section would otherwise hide the
 * very elements the plugin is trying to resolve a grant against, and every policy would evaluate
 * to nothing. {@see \justinholtweb\seclude\services\Sources} needs the second, because it counts a
 * secluded user's content while rendering an index page rather than while listing one.
 *
 * @property ElementQuery $owner
 */
class SecludeQueryBehavior extends Behavior
{
    /** Null leaves the decision to {@see \justinholtweb\seclude\services\QueryFilter::isArmed()}. */
    public ?bool $seclude = null;

    public function seclude(?bool $value = true): ElementQuery
    {
        $this->seclude = $value;

        return $this->owner;
    }
}
