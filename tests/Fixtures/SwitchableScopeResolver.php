<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures;

use Aapolrac\AccessControl\Contracts\ScopeResolver as ScopeResolverContract;
use Illuminate\Database\Eloquent\Model;

class SwitchableScopeResolver implements ScopeResolverContract
{
    public function __construct(public ?int $scopeId = null) {}

    public function resolveScopeId(?Model $scope = null): ?int
    {
        if ($scope !== null) {
            return (int) $scope->getKey();
        }

        return $this->scopeId;
    }
}
