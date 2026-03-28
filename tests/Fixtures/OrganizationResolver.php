<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures;

use Aapolrac\AccessControl\Contracts\OrganizationResolver as OrganizationResolverContract;
use Illuminate\Database\Eloquent\Model;

class OrganizationResolver extends ScopeResolver implements OrganizationResolverContract
{
    public function resolveOrganizationId(?Model $organization = null): ?int
    {
        return $this->resolveScopeId($organization);
    }
}
