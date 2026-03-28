<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Support;

use Aapolrac\AccessControl\Contracts\OrganizationResolver;
use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Contracts\TenantResolver;
use Illuminate\Database\Eloquent\Model;

class DefaultScopeResolver implements ScopeResolver, OrganizationResolver, TenantResolver
{
    public function resolveScopeId(?Model $scope = null): ?int
    {
        if ($scope !== null) {
            return (int) $scope->getKey();
        }

        return null;
    }

    /** @deprecated Use resolveScopeId instead. */
    public function resolveOrganizationId(?Model $organization = null): ?int
    {
        return $this->resolveScopeId($organization);
    }
}
