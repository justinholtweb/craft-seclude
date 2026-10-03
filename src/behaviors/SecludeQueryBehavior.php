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
 * ## Settable by method only
 *
 * The flag is private on purpose. Craft's element index and selector controllers pass posted
 * `criteria` through `Craft::configure()`, and Yii writes a configured key into any *public*
 * property of an attached behaviour — so a public `$seclude` let anybody post `criteria[seclude]=0`
 * and have the very listing the filter guards come back unfiltered. With no public property and
 * no setter, the same post is an unknown-property error instead.
 *
 * @property ElementQuery $owner
 * @property-read bool|null $secludeFlag
 */
class SecludeQueryBehavior extends Behavior
{
    /** Null leaves the decision to {@see \justinholtweb\seclude\services\QueryFilter::isArmed()}. */
    private ?bool $_seclude = null;

    public function seclude(?bool $value = true): ElementQuery
    {
        $this->_seclude = $value;

        return $this->owner;
    }

    public function getSecludeFlag(): ?bool
    {
        return $this->_seclude;
    }
}
