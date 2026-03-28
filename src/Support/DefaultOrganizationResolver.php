<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Support;

use Aapolrac\AccessControl\Contracts\OrganizationResolver;
use Illuminate\Database\Eloquent\Model;

class DefaultOrganizationResolver implements OrganizationResolver
{
    public function resolveOrganizationId(?Model $organization = null): ?int
    {
        if ($organization) {
            return (int) $organization->getKey();
        }

        return null;
    }
}
