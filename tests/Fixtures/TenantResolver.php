<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures;

use Aapolrac\AccessControl\Contracts\TenantResolver as TenantResolverContract;
use Illuminate\Database\Eloquent\Model;

class TenantResolver extends OrganizationResolver implements TenantResolverContract
{
    public function resolveOrganizationId(?Model $tenant = null): ?int
    {
        return $this->resolveScopeId($tenant);
    }
}
