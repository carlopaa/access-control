<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class ScopeResolver implements \Aapolrac\AccessControl\Contracts\ScopeResolver
{
    public function __construct(private readonly ?int $scopeId = null) {}

    public function resolveScopeId(?Model $scope = null): ?int
    {
        if ($scope !== null) {
            return (int) $scope->getKey();
        }

        return $this->scopeId;
    }
}
