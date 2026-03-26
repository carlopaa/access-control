<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Support;

use Aapolrac\AccessControl\Contracts\TenantResolver;
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
