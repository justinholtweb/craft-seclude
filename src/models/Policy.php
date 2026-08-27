<?php

declare(strict_types=1);

namespace justinholtweb\seclude\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\seclude\grants\GrantInterface;
use justinholtweb\seclude\Plugin;
use justinholtweb\seclude\records\PolicyRecord;

/**
 * One statement of the form: *these users, in this scope, get these elements, and may do this
 * much to them.*
 *
 * Policies union. Two policies naming the same editor add their grants together; neither can take
 * away what the other gives. What *does* take away is scope: the moment any enabled policy names a
 * user and covers a section, that user is secluded in that section and sees only what some policy
 * grants them there.
 */
class Policy extends Model
{
    public ?int $id = null;
    public ?string $uid = null;
    public string $name = '';
    public string $handle = '';
    public bool $enabled = true;
    public int $sortOrder = 0;

    public Subjects $subjects;
    public TargetScope $scope;
    public Abilities $abilities;

    /** @var GrantInterface[] */
    public array $grants = [];

    /**
     * Also grant everything beneath a granted element in its structure.
     *
     * A policy-level expansion rather than a grant of its own, so it composes over all of them:
     * assign somebody a documentation section's landing page and they get the whole branch,
     * including pages written after the assignment was made.
     */
    public bool $includeDescendants = false;

    /** How many levels down. Null is unlimited. */
    public ?int $descendantDepth = null;

    public function __construct($config = [])
    {
        $config['subjects'] = $this->hydrate($config['subjects'] ?? [], Subjects::class);
        $config['scope'] = $this->hydrate($config['scope'] ?? [], TargetScope::class);
        $config['abilities'] = $this->hydrate($config['abilities'] ?? [], Abilities::class);
        $config['grants'] = $this->hydrateGrants($config['grants'] ?? []);

        parent::__construct($config);
    }

    private function hydrate(mixed $value, string $class): Model
    {
        if ($value instanceof $class) {
            return $value;
        }

        return new $class(is_array($value) ? $value : []);
    }

    /** @return GrantInterface[] */
    private function hydrateGrants(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $grants = [];

        foreach ($value as $item) {
            if ($item instanceof GrantInterface) {
                $grants[] = $item;
                continue;
            }

            if (!is_array($item) || !isset($item['type'])) {
                continue;
            }

            $grant = Plugin::getInstance()->policies->createGrant($item);

            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']],
            [['handle'], UniqueValidator::class, 'targetClass' => PolicyRecord::class, 'targetAttribute' => 'handle'],
            [['descendantDepth'], 'integer', 'min' => 1],
            [['subjects'], 'validateSubjects'],
            [['scope'], 'validateScope'],
            [['grants'], 'validateGrants'],
            [['abilities'], 'validateAbilities'],
        ];
    }

    public function validateSubjects(): void
    {
        if ($this->subjects->isEmpty()) {
            $this->addError('subjects', Craft::t('seclude', 'Choose who this policy applies to.'));
        }
    }

    public function validateScope(): void
    {
        if ($this->scope->elementType() === null) {
            $this->addError('scope', Craft::t('seclude', 'Choose what this policy governs.'));
        }
    }

    public function validateGrants(): void
    {
        if ($this->grants === []) {
            // A policy with no grants secludes its subjects and then gives them nothing — a
            // section that silently empties out. Almost always a half-finished policy rather than
            // a deliberate lockout, and refusing it at save time is far kinder than shipping it.
            $this->addError('grants', Craft::t('seclude', 'Add at least one grant, or this policy hides everything in its scope.'));
        }

        foreach ($this->grants as $grant) {
            $reason = $grant->unresolvableReason($this);

            if ($reason !== null) {
                $this->addError('grants', $reason);
            }
        }
    }

    public function validateAbilities(): void
    {
        if ($this->abilities->isEmpty()) {
            $this->addError('abilities', Craft::t('seclude', 'A policy that permits nothing is the same as no access at all. Grant at least one ability.'));
        }
    }

    /**
     * Whether this policy can be enforced right now.
     *
     * Unlike Bouncer, an unevaluable policy is **not** enforced — see `docs/plan.md`. A missing
     * section here would otherwise deny an entire editorial team on the strength of a stale UID.
     */
    public function isEvaluable(): bool
    {
        return $this->unevaluableReasons() === [];
    }

    /** @return string[] */
    public function unevaluableReasons(): array
    {
        $reasons = [];

        if ($this->scope->elementType() === null) {
            $reasons[] = Craft::t('seclude', 'Its scope names an element type this install does not have.');
        } elseif (!$this->scope->isResolvable()) {
            $reasons[] = Craft::t('seclude', 'A section, group or volume in its scope has been deleted.');
        }

        if (!$this->subjects->isResolvable()) {
            $reasons[] = Craft::t('seclude', 'A user group or user it names has been deleted.');
        }

        if ($this->grants === []) {
            $reasons[] = Craft::t('seclude', 'It has no grants.');
        }

        foreach ($this->grants as $grant) {
            $reason = $grant->unresolvableReason($this);

            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /** Whether any grant reads the assignment table, so the CP knows to offer the screens. */
    public function usesAssignments(): bool
    {
        foreach ($this->grants as $grant) {
            if ($grant->usesAssignments()) {
                return true;
            }
        }

        return false;
    }

    public function getGrantsByType(string $type): array
    {
        return array_values(array_filter($this->grants, static fn(GrantInterface $g) => $g::type() === $type));
    }

    public function describeGrants(): array
    {
        return array_map(fn(GrantInterface $g) => $g->describe($this), $this->grants);
    }

    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'enabled' => $this->enabled,
            'sortOrder' => $this->sortOrder,
            'subjects' => $this->subjects->getConfig(),
            'scope' => $this->scope->getConfig(),
            'abilities' => $this->abilities->getConfig(),
            'grants' => array_map(static fn(GrantInterface $g) => $g->getConfig(), $this->grants),
            'includeDescendants' => $this->includeDescendants,
            'descendantDepth' => $this->descendantDepth,
        ];
    }

    public function ensureUid(): string
    {
        return $this->uid ??= StringHelper::UUID();
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->handle;
    }
}
