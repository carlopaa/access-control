<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Support;

use Illuminate\Database\Eloquent\Model;

class DefaultOrganizationResolver extends DefaultScopeResolver
{
    /** @deprecated Use DefaultScopeResolver instead. */
    public function resolveOrganizationId(?Model $organization = null): ?int
    {
        return $this->resolveScopeId($organization);
    }
}
