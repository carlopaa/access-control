<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Support;

use Illuminate\Database\Eloquent\Model;

class DefaultTenantResolver extends DefaultOrganizationResolver
{
    /** @deprecated Use DefaultOrganizationResolver instead. */
    public function resolveOrganizationId(?Model $tenant = null): ?int
    {
        return parent::resolveOrganizationId($tenant);
    }
}
