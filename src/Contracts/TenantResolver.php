<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TenantResolver
{
    public function resolveOrganizationId(?Model $tenant = null): ?int;
}
