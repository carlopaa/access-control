<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures;

use Aapolrac\AccessControl\Contracts\OrganizationResolver as OrganizationResolverContract;
use Illuminate\Database\Eloquent\Model;

class OrganizationResolver implements OrganizationResolverContract
{
    public function __construct(private readonly ?int $organizationId = null) {}

    public function resolveOrganizationId(?Model $organization = null): ?int
    {
        if ($organization !== null) {
            return (int) $organization->getKey();
        }

        return $this->organizationId;
    }
}
