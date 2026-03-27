<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures;

use Aapolrac\AccessControl\Contracts\TenantResolver as TenantResolverContract;
use Illuminate\Database\Eloquent\Model;

class TenantResolver implements TenantResolverContract
{
    public function __construct(private readonly ?int $organizationId = null) {}

    public function resolveOrganizationId(?Model $tenant = null): ?int
    {
        if ($tenant !== null) {
            return (int) $tenant->getKey();
        }

        return $this->organizationId;
    }
}
