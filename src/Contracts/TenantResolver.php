<?php

namespace Aapolrac\Rbac\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TenantResolver
{
    public function resolveOrganizationId(?Model $tenant = null): ?int;
}
