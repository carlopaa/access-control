<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ScopeResolver
{
    public function resolveScopeId(?Model $scope = null): ?int;
}
