<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Contracts;

use Illuminate\Database\Eloquent\Model;

interface OrganizationResolver extends ScopeResolver
{
    /** @deprecated Use resolveScopeId instead. */
    public function resolveOrganizationId(?Model $organization = null): ?int;
}
