<?php

declare(strict_types=1);

namespace justinholtweb\seclude\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\base\Model;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;

/**
 * What a policy governs: an element type, and which of that type's containers.
 *
 * This is the only class that knows a section is to an entry what a volume is to an asset. Every
 * other part of Seclude asks it "is this element in scope" or "what SQL means in scope", so
 * adding a fifth element type later is a change here and nowhere else.
 *
 * An empty `sourceUids` means *every* container of that type. It is written out that way rather
 * than expanded on save so that a section added next year is covered without anybody remembering
 * to re-open the policy — which is the failure mode that makes permissions plugins leak.
 */
class TargetScope extends Model
{
    public const TYPE_ENTRIES = 'entries';
    public const TYPE_CATEGORIES = 'categories';
    public const TYPE_ASSETS = 'assets';
    public const TYPE_USERS = 'users';

    public string $type = self::TYPE_ENTRIES;

    /** Section / category group / volume / user group UIDs. Empty means all of them. */
    public array $sourceUids = [];

    /** Entry types, for narrowing an entries scope further. Empty means all. Entries only. */
    public array $entryTypeUids = [];

    private ?array $_sourceIds = null;
    private ?array $_entryTypeIds = null;

    public static function types(): array
    {
        return [self::TYPE_ENTRIES, self::TYPE_CATEGORIES, self::TYPE_ASSETS, self::TYPE_USERS];
    }

    public static function elementTypeFor(string $type): ?string
    {
        return match ($type) {
            self::TYPE_ENTRIES => Entry::class,
            self::TYPE_CATEGORIES => Category::class,
            self::TYPE_ASSETS => Asset::class,
            self::TYPE_USERS => User::class,
            default => null,
        };
    }

    public function elementType(): ?string
    {
        return self::elementTypeFor($this->type);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_ENTRIES => Craft::t('seclude', 'Entries'),
            self::TYPE_CATEGORIES => Craft::t('seclude', 'Categories'),
            self::TYPE_ASSETS => Craft::t('seclude', 'Assets'),
            self::TYPE_USERS => Craft::t('seclude', 'Users'),
            default => $this->type,
        };
    }

    /** Whether this scope could govern elements of the given class. */
    public function coversElementType(string $elementType): bool
    {
        $mine = $this->elementType();

        // `is_a` not `===`, so a site that subclasses Entry is still governed. Getting this wrong
        // means the policy quietly stops applying the day somebody customises an element type.
        return $mine !== null && is_a($elementType, $mine, true);
    }

    /**
     * Whether this element falls inside the scope.
     *
     * Assumes the element has already been resolved to its canonical, non-nested self by
     * {@see \justinholtweb\seclude\services\Authority::subject()}. A nested entry has no section,
     * so judging one here would put it outside every scope and quietly allow it.
     */
    public function contains(ElementInterface $element): bool
    {
        if (!$this->coversElementType($element::class)) {
            return false;
        }

        $ids = $this->sourceIds();

        if ($element instanceof Entry) {
            if ($element instanceof NestedElementInterface && $element->getOwnerId() !== null) {
                return false;
            }

            $sectionId = $element->getSection()?->id;

            if ($sectionId === null) {
                return false;
            }

            if ($ids !== null && !in_array($sectionId, $ids, true)) {
                return false;
            }

            $typeIds = $this->entryTypeIds();

            return $typeIds === null || in_array($element->getType()->id, $typeIds, true);
        }

        if ($element instanceof Category) {
            $groupId = $element->getGroup()->id;

            return $ids === null || in_array($groupId, $ids, true);
        }

        if ($element instanceof Asset) {
            $volumeId = $element->getVolumeId();

            return $volumeId !== null && ($ids === null || in_array($volumeId, $ids, true));
        }

        if ($element instanceof User) {
            if ($ids === null) {
                return true;
            }

            foreach ($element->getGroups() as $group) {
                if (in_array($group->id, $ids, true)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * A SQL condition, in terms of `elements.id` alone, for "this row is inside the scope".
     *
     * Deliberately `EXISTS` subqueries rather than conditions on the joined `entries` / `assets`
     * tables. Those aliases exist in an element query today, but a condition that names one is a
     * condition that breaks the day Craft changes how a query is assembled — and it breaks by
     * throwing a SQL error on every CP listing. `elements.id` is the one column guaranteed to be
     * in scope in an element query's subquery.
     */
    public function inScopeCondition(): array
    {
        $ids = $this->sourceIds();

        return match ($this->type) {
            self::TYPE_ENTRIES => $this->entriesCondition($ids),
            self::TYPE_CATEGORIES => ['exists', (new Query())
                ->from(['seclude_sc' => Table::CATEGORIES])
                ->where('[[seclude_sc.id]] = [[elements.id]]')
                ->andFilterWhere(['seclude_sc.groupId' => $ids]),
            ],
            self::TYPE_ASSETS => ['exists', (new Query())
                ->from(['seclude_sa' => Table::ASSETS])
                ->where('[[seclude_sa.id]] = [[elements.id]]')
                ->andFilterWhere(['seclude_sa.volumeId' => $ids]),
            ],
            self::TYPE_USERS => $this->usersCondition($ids),
            default => ['and', '1=0'],
        };
    }

    private function entriesCondition(?array $ids): array
    {
        $query = (new Query())
            ->from(['seclude_se' => Table::ENTRIES])
            ->where('[[seclude_se.id]] = [[elements.id]]')
            // A nested entry's `sectionId` is null. It is never in scope on its own — it is
            // governed through whichever entry owns it.
            ->andWhere(['not', ['seclude_se.sectionId' => null]])
            ->andFilterWhere(['seclude_se.sectionId' => $ids]);

        $typeIds = $this->entryTypeIds();

        if ($typeIds !== null) {
            $query->andWhere(['seclude_se.typeId' => $typeIds]);
        }

        return ['exists', $query];
    }

    private function usersCondition(?array $ids): array
    {
        if ($ids === null) {
            return ['exists', (new Query())
                ->from(['seclude_su' => Table::USERS])
                ->where('[[seclude_su.id]] = [[elements.id]]'),
            ];
        }

        return ['exists', (new Query())
            ->from(['seclude_sug' => Table::USERGROUPS_USERS])
            ->where('[[seclude_sug.userId]] = [[elements.id]]')
            ->andWhere(['seclude_sug.groupId' => $ids]),
        ];
    }

    /**
     * Narrow an element query to this scope. Used when resolving grants, never on a user's query.
     */
    public function constrain(Query $query): Query
    {
        return $query->andWhere($this->inScopeCondition());
    }

    /** Container IDs, or null for "all of them". */
    public function sourceIds(): ?array
    {
        if ($this->sourceUids === []) {
            return null;
        }

        return $this->_sourceIds ??= $this->resolveSourceIds();
    }

    private function resolveSourceIds(): array
    {
        $ids = [];

        foreach ($this->sourceUids as $uid) {
            $id = match ($this->type) {
                self::TYPE_ENTRIES => Craft::$app->getEntries()->getSectionByUid($uid)?->id,
                self::TYPE_CATEGORIES => Craft::$app->getCategories()->getGroupByUid($uid)?->id,
                self::TYPE_ASSETS => Craft::$app->getVolumes()->getVolumeByUid($uid)?->id,
                self::TYPE_USERS => Craft::$app->getUserGroups()->getGroupByUid($uid)?->id,
                default => null,
            };

            if ($id !== null) {
                $ids[] = (int)$id;
            }
        }

        // Never fall back to "all" when every named source has been deleted. That would silently
        // widen the policy from three sections to the entire site. A scope that resolves to
        // nothing matches nothing, and {@see self::isResolvable()} reports it.
        return $ids === [] ? [0] : $ids;
    }

    public function entryTypeIds(): ?array
    {
        if ($this->type !== self::TYPE_ENTRIES || $this->entryTypeUids === []) {
            return null;
        }

        if ($this->_entryTypeIds === null) {
            $ids = [];

            foreach ($this->entryTypeUids as $uid) {
                $entryType = Craft::$app->getEntries()->getEntryTypeByUid($uid);

                if ($entryType !== null) {
                    $ids[] = (int)$entryType->id;
                }
            }

            $this->_entryTypeIds = $ids === [] ? [0] : $ids;
        }

        return $this->_entryTypeIds;
    }

    /** Whether every source named here still exists. */
    public function isResolvable(): bool
    {
        if ($this->elementType() === null) {
            return false;
        }

        foreach ($this->sourceUids as $uid) {
            $exists = match ($this->type) {
                self::TYPE_ENTRIES => Craft::$app->getEntries()->getSectionByUid($uid) !== null,
                self::TYPE_CATEGORIES => Craft::$app->getCategories()->getGroupByUid($uid) !== null,
                self::TYPE_ASSETS => Craft::$app->getVolumes()->getVolumeByUid($uid) !== null,
                self::TYPE_USERS => Craft::$app->getUserGroups()->getGroupByUid($uid) !== null,
                default => false,
            };

            if (!$exists) {
                return false;
            }
        }

        foreach ($this->entryTypeUids as $uid) {
            if (Craft::$app->getEntries()->getEntryTypeByUid($uid) === null) {
                return false;
            }
        }

        return true;
    }

    /** Human labels for the CP index. */
    public function describe(): array
    {
        if ($this->sourceUids === []) {
            return [Craft::t('seclude', 'All {type}', ['type' => mb_strtolower($this->typeLabel())])];
        }

        $out = [];

        foreach ($this->sourceUids as $uid) {
            $name = match ($this->type) {
                self::TYPE_ENTRIES => Craft::$app->getEntries()->getSectionByUid($uid)?->name,
                self::TYPE_CATEGORIES => Craft::$app->getCategories()->getGroupByUid($uid)?->name,
                self::TYPE_ASSETS => Craft::$app->getVolumes()->getVolumeByUid($uid)?->name,
                self::TYPE_USERS => Craft::$app->getUserGroups()->getGroupByUid($uid)?->name,
                default => null,
            };

            $out[] = $name ?? Craft::t('seclude', 'Missing source');
        }

        return $out;
    }

    public function getConfig(): array
    {
        return [
            'type' => $this->type,
            'sourceUids' => array_values($this->sourceUids),
            'entryTypeUids' => array_values($this->entryTypeUids),
        ];
    }
}
