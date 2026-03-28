<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Support;

use Illuminate\Database\Eloquent\Model;

class DefaultTenantResolver extends DefaultOrganizationResolver
{
    /** @deprecated Use DefaultScopeResolver instead. */
    public function resolveOrganizationId(?Model $tenant = null): ?int
    {
        return $this->resolveScopeId($tenant);
    }
}
