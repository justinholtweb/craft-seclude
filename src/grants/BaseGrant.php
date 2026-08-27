<?php

declare(strict_types=1);

namespace justinholtweb\seclude\grants;

use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\User;
use justinholtweb\seclude\models\Policy;

abstract class BaseGrant extends Model implements GrantInterface
{
    public function contains(ElementInterface $element, User $user, Policy $policy): ?bool
    {
        return null;
    }

    public function isResolvable(Policy $policy): bool
    {
        return $this->unresolvableReason($policy) === null;
    }

    public function unresolvableReason(Policy $policy): ?string
    {
        return null;
    }

    public function usesAssignments(): bool
    {
        return false;
    }

    public function getConfig(): array
    {
        return ['type' => static::type()];
    }
}
