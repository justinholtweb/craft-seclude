<?php

declare(strict_types=1);

namespace justinholtweb\seclude\models;

use Craft;

/**
 * What Seclude has to say about one (user, element, ability) question.
 *
 * Three answers, not two. **Silent** is the important one and the reason this is a model rather
 * than a bool: it means no policy governs this, so Craft's own permissions decide and Seclude
 * hands back `null` to the authorization event. Collapsing silent into allowed would make Seclude
 * *grant* access, and Seclude never grants access — see the invariant in `docs/plan.md`.
 */
class Verdict
{
    public const SILENT = 'silent';
    public const ALLOWED = 'allowed';
    public const DENIED = 'denied';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?Policy $policy = null,
        public readonly ?string $grantType = null,
    ) {
    }

    public static function silent(string $reason): self
    {
        return new self(self::SILENT, $reason);
    }

    public static function allowed(string $reason, ?Policy $policy = null, ?string $grantType = null): self
    {
        return new self(self::ALLOWED, $reason, $policy, $grantType);
    }

    public static function denied(string $reason, ?Policy $policy = null): self
    {
        return new self(self::DENIED, $reason, $policy);
    }

    public function isSilent(): bool
    {
        return $this->outcome === self::SILENT;
    }

    public function isAllowed(): bool
    {
        return $this->outcome === self::ALLOWED;
    }

    public function isDenied(): bool
    {
        return $this->outcome === self::DENIED;
    }

    /**
     * The value to hand an `AuthorizationCheckEvent`.
     *
     * Note what is missing: this never returns `true`. An allowed verdict still defers to Craft,
     * because "Seclude does not object" is not the same as "this user may edit this". Craft's own
     * section permissions still have to say yes.
     */
    public function forAuthorizationEvent(): ?bool
    {
        return $this->isDenied() ? false : null;
    }

    public function outcomeLabel(): string
    {
        return match ($this->outcome) {
            self::SILENT => Craft::t('seclude', 'Not governed'),
            self::ALLOWED => Craft::t('seclude', 'Permitted'),
            self::DENIED => Craft::t('seclude', 'Refused'),
            default => $this->outcome,
        };
    }

    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'policy' => $this->policy?->handle,
            'grant' => $this->grantType,
        ];
    }
}
