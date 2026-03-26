<?php

namespace Aapolrac\Rbac\Support;

use Aapolrac\Rbac\Contracts\TenantResolver;
use Illuminate\Database\Eloquent\Model;

class DefaultTenantResolver implements TenantResolver
{
    public function resolveOrganizationId(?Model $tenant = null): ?int
    {
        if ($tenant) {
            return (int) $tenant->getKey();
        }

        return null;
    }
}
