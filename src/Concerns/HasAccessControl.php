<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Concerns;

use Aapolrac\AccessControl\Contracts\OrganizationResolver;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

trait HasAccessControl
{
    public function initializeHasAccessControl(): void
    {
        if (method_exists($this, 'mergeCasts')) {
            $this->mergeCasts(['permissions' => 'array']);
        }
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            (string) config('access_control.models.role'),
            (string) config('access_control.tables.role_user', 'role_user'),
            'user_id',
            'role_id'
        )->withPivot('organization_id')->withTimestamps();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            (string) config('access_control.models.group'),
            (string) config('access_control.tables.group_user', 'group_user'),
            'user_id',
            'group_id'
        )->withPivot('organization_id')->withTimestamps();
    }

    public function getAllPermissions(): Collection
    {
        $authenticatedUserId = Auth::id();
        $cacheEnabled = (bool) config('access_control.context_cache.enabled', true);
        $cacheKey = (string) config('access_control.context_cache.key', 'permissions');

        if ($cacheEnabled && $authenticatedUserId === $this->id && Context::hasHidden($cacheKey)) {
            return collect(Context::getHidden($cacheKey));
        }

        $groupPermissions = $this->groups()
            ->with('permissions')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name');

        $directPermissions = $this->getDirectPermissions();

        $permissions = $groupPermissions
            ->merge($directPermissions)
            ->unique()
            ->map(static fn ($item) => strtolower((string) $item))
            ->values();

        if ($cacheEnabled && $authenticatedUserId === $this->id) {
            Context::addHidden($cacheKey, $permissions->all());
        }

        return $permissions;
    }

    public function getDirectPermissions(): Collection
    {
        return collect($this->permissions ?? [])
            ->map(fn ($permission) => $this->normalizePermission($permission))
            ->filter()
            ->unique()
            ->values();
    }

    public function assignPermission(BackedEnum|string $permission): static
    {
        return $this->assignPermissions([$permission]);
    }

    public function assignRole(BackedEnum|string|int $role, mixed $organization): static
    {
        return $this->assignRoles([$role], $organization);
    }

    public function assignRoles(array $roles, mixed $organization): static
    {
        return $this->syncBelongsToManyInOrganization(
            relation: 'roles',
            ids: $this->resolveRoleIds($roles),
            organization: $organization,
            mode: 'attach'
        );
    }

    public function syncRoles(array $roles, mixed $organization): static
    {
        return $this->syncBelongsToManyInOrganization(
            relation: 'roles',
            ids: $this->resolveRoleIds($roles),
            organization: $organization,
            mode: 'sync'
        );
    }

    public function revokeRole(BackedEnum|string|int $role, mixed $organization): static
    {
        return $this->syncBelongsToManyInOrganization(
            relation: 'roles',
            ids: $this->resolveRoleIds([$role]),
            organization: $organization,
            mode: 'detach'
        );
    }

    public function assignGroup(BackedEnum|string|int $group, mixed $organization): static
    {
        return $this->assignGroups([$group], $organization);
    }

    public function assignGroups(array $groups, mixed $organization): static
    {
        return $this->syncBelongsToManyInOrganization(
            relation: 'groups',
            ids: $this->resolveGroupIds($groups),
            organization: $organization,
            mode: 'attach'
        );
    }

    public function syncGroups(array $groups, mixed $organization): static
    {
        return $this->syncBelongsToManyInOrganization(
            relation: 'groups',
            ids: $this->resolveGroupIds($groups),
            organization: $organization,
            mode: 'sync'
        );
    }

    public function revokeGroup(BackedEnum|string|int $group, mixed $organization): static
    {
        return $this->syncBelongsToManyInOrganization(
            relation: 'groups',
            ids: $this->resolveGroupIds([$group]),
            organization: $organization,
            mode: 'detach'
        );
    }

    public function assignPermissions(array $permissions): static
    {
        $updatedPermissions = $this->getDirectPermissions()
            ->merge($this->normalizePermissionValues($permissions))
            ->unique()
            ->values();

        $this->forceFill([
            'permissions' => $updatedPermissions->all(),
        ])->save();

        $this->forgetPermissionContextCache();

        return $this;
    }

    public function revokePermission(BackedEnum|string $permission): static
    {
        $permissionValue = $this->normalizePermission($permission);

        $updatedPermissions = $this->getDirectPermissions()
            ->reject(fn (string $existingPermission) => $existingPermission === $permissionValue)
            ->values();

        $this->forceFill([
            'permissions' => $updatedPermissions->all(),
        ])->save();

        $this->forgetPermissionContextCache();

        return $this;
    }

    public function syncDirectPermissions(array $permissions): static
    {
        $updatedPermissions = $this->normalizePermissionValues($permissions)
            ->unique()
            ->values();

        $this->forceFill([
            'permissions' => $updatedPermissions->all(),
        ])->save();

        $this->forgetPermissionContextCache();

        return $this;
    }

    public function clearDirectPermissions(): static
    {
        $this->forceFill([
            'permissions' => [],
        ])->save();

        $this->forgetPermissionContextCache();

        return $this;
    }

    public function hasPermission(BackedEnum|string $permission): bool
    {
        $permissionValue = $this->normalizePermission($permission);

        if ($this->isDeniedPermission($permissionValue)) {
            return $this->getAllPermissions()->contains($permissionValue);
        }

        if ($this->getAllPermissions()->contains($this->denyPermissionName($permissionValue))) {
            return false;
        }

        return $this->getAllPermissions()->contains($permissionValue);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return collect($permissions)
            ->contains(fn ($permission) => $this->hasPermission($permission));
    }

    public function hasRole(BackedEnum|string $role): bool
    {
        return $this->roles()->where('key', $this->normalizeEnumOrString($role))->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        $roleValues = array_map(fn ($role) => $this->normalizeEnumOrString($role), $roles);

        return $this->roles()->whereIn('key', $roleValues)->exists();
    }

    public function hasRoleInOrg(BackedEnum|string $role, int $organizationId): bool
    {
        $roleTable = (string) config('access_control.tables.roles', 'roles');

        return $this->roles()
            ->where($roleTable.'.key', $this->normalizeEnumOrString($role))
            ->wherePivot('organization_id', $organizationId)
            ->exists();
    }

    public function hasAnyRoleInOrg(array $roles, int $organizationId): bool
    {
        $roleValues = array_map(fn ($role) => $this->normalizeEnumOrString($role), $roles);
        $roleTable = (string) config('access_control.tables.roles', 'roles');

        return $this->roles()
            ->whereIn($roleTable.'.key', $roleValues)
            ->wherePivot('organization_id', $organizationId)
            ->exists();
    }

    public function scopeWithRole(Builder $query, BackedEnum|string $role): Builder
    {
        $roleValue = $this->normalizeEnumOrString($role);
        $roleTable = (string) config('access_control.tables.roles', 'roles');

        return $query->whereHas('roles', function ($builder) use ($roleTable, $roleValue) {
            $builder->where($roleTable.'.key', $roleValue);
        });
    }

    public function scopeWithAnyRoles(Builder $query, array $roles): Builder
    {
        $roleValues = array_map(fn ($role) => $this->normalizeEnumOrString($role), $roles);
        $roleTable = (string) config('access_control.tables.roles', 'roles');

        return $query->whereHas('roles', function ($builder) use ($roleTable, $roleValues) {
            $builder->whereIn($roleTable.'.key', $roleValues);
        });
    }

    public function scopeWithRoleInOrg(Builder $query, BackedEnum|string $role, mixed $organization = null): Builder
    {
        $roleValue = $this->normalizeEnumOrString($role);
        $organizationId = $this->resolveOrganizationId($organization);
        $roleTable = (string) config('access_control.tables.roles', 'roles');
        $roleUserTable = (string) config('access_control.tables.role_user', 'role_user');

        if ($organizationId === null) {
            return $query->whereHas('roles', function ($builder) use ($roleTable, $roleValue) {
                $builder->where($roleTable.'.key', $roleValue);
            });
        }

        return $query->whereHas('roles', function ($builder) use ($roleTable, $roleUserTable, $roleValue, $organizationId) {
            $builder
                ->where($roleTable.'.key', $roleValue)
                ->where($roleUserTable.'.organization_id', $organizationId);
        });
    }

    public function scopeWithAnyRolesInOrg(Builder $query, array $roles, mixed $organization = null): Builder
    {
        $roleValues = array_map(fn ($role) => $this->normalizeEnumOrString($role), $roles);
        $organizationId = $this->resolveOrganizationId($organization);
        $roleTable = (string) config('access_control.tables.roles', 'roles');
        $roleUserTable = (string) config('access_control.tables.role_user', 'role_user');

        if ($organizationId === null) {
            return $query->whereHas('roles', function ($builder) use ($roleTable, $roleValues) {
                $builder->whereIn($roleTable.'.key', $roleValues);
            });
        }

        return $query->whereHas('roles', function ($builder) use ($roleTable, $roleUserTable, $roleValues, $organizationId) {
            $builder
                ->whereIn($roleTable.'.key', $roleValues)
                ->where($roleUserTable.'.organization_id', $organizationId);
        });
    }

    protected function normalizeEnumOrString(BackedEnum|string $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    protected function normalizePermission(BackedEnum|string $permission): string
    {
        if ($permission instanceof BackedEnum) {
            return strtolower((string) $permission->value);
        }

        return strtolower((string) $permission);
    }

    protected function normalizePermissionValues(array $permissions): Collection
    {
        return collect($permissions)
            ->map(fn ($permission) => $this->normalizePermission($permission))
            ->filter();
    }

    protected function isDeniedPermission(string $permission): bool
    {
        return str_ends_with($permission, ':deny');
    }

    protected function denyPermissionName(string $permission): string
    {
        return $permission.':deny';
    }

    protected function forgetPermissionContextCache(): void
    {
        $authenticatedUserId = Auth::id();
        $cacheEnabled = (bool) config('access_control.context_cache.enabled', true);
        $cacheKey = (string) config('access_control.context_cache.key', 'permissions');

        if ($cacheEnabled && $authenticatedUserId === $this->id) {
            Context::forgetHidden($cacheKey);
        }
    }

    protected function resolveOrganizationId(mixed $organization = null): ?int
    {
        if ($organization !== null) {
            if (is_object($organization) && method_exists($organization, 'getKey')) {
                return (int) $organization->getKey();
            }

            return (int) $organization;
        }

        /** @var OrganizationResolver $resolver */
        $resolver = app(OrganizationResolver::class);

        return $resolver->resolveOrganizationId();
    }

    protected function resolveRoleIds(array $roles): array
    {
        return $this->resolveRelationIds(
            values: $roles,
            modelClass: (string) config('access_control.models.role'),
        );
    }

    protected function resolveGroupIds(array $groups): array
    {
        return $this->resolveRelationIds(
            values: $groups,
            modelClass: (string) config('access_control.models.group'),
        );
    }

    protected function resolveRelationIds(array $values, string $modelClass): array
    {
        $normalizedValues = collect($values)
            ->map(function ($value) {
                if ($value instanceof BackedEnum) {
                    return $value->value;
                }

                return $value;
            })
            ->filter(static fn ($value) => $value !== null && $value !== '')
            ->values();

        $ids = $normalizedValues
            ->filter(static fn ($value) => is_int($value) || ctype_digit((string) $value))
            ->map(static fn ($value) => (int) $value)
            ->all();

        $keys = $normalizedValues
            ->filter(static fn ($value) => is_string($value) && ! ctype_digit($value))
            ->map(static fn (string $value) => strtolower($value))
            ->all();

        $idsByKey = empty($keys)
            ? []
            : $modelClass::query()
                ->whereIn('key', $keys)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();

        return array_values(array_unique([...$ids, ...$idsByKey]));
    }

    protected function syncBelongsToManyInOrganization(
        string $relation,
        array $ids,
        mixed $organization,
        string $mode
    ): static {
        $organizationId = $this->resolveOrganizationId($organization);

        if ($organizationId === null) {
            throw new \InvalidArgumentException('An organization is required for role and group assignments.');
        }

        $relatedIds = array_values(array_unique(array_map('intval', $ids)));

        /** @var BelongsToMany $relationQuery */
        $relationQuery = $this->{$relation}();

        $payload = [];

        foreach ($relatedIds as $id) {
            $payload[$id] = ['organization_id' => $organizationId];
        }

        if ($mode === 'attach') {
            if ($payload !== []) {
                $relationQuery->syncWithoutDetaching($payload);
            }

            return $this;
        }

        $pivotTable = $relationQuery->getTable();
        $relatedPivotKey = $relationQuery->getRelatedPivotKeyName();

        if ($mode === 'detach') {
            if ($relatedIds !== []) {
                $this->{$relation}()
                    ->newPivotStatement()
                    ->where($relationQuery->getForeignPivotKeyName(), $this->getKey())
                    ->where('organization_id', $organizationId)
                    ->whereIn($relatedPivotKey, $relatedIds)
                    ->delete();
            }

            return $this;
        }

        if ($mode === 'sync') {
            $currentIds = $this->{$relation}()
                ->newPivotStatement()
                ->where($relationQuery->getForeignPivotKeyName(), $this->getKey())
                ->where('organization_id', $organizationId)
                ->pluck($relatedPivotKey)
                ->map(static fn ($id) => (int) $id)
                ->all();

            $toDetach = array_values(array_diff($currentIds, $relatedIds));

            if ($toDetach !== []) {
                $this->{$relation}()
                    ->newPivotStatement()
                    ->where($relationQuery->getForeignPivotKeyName(), $this->getKey())
                    ->where('organization_id', $organizationId)
                    ->whereIn($relatedPivotKey, $toDetach)
                    ->delete();
            }

            if ($payload !== []) {
                $relationQuery->syncWithoutDetaching($payload);
            }

            return $this;
        }

        throw new \InvalidArgumentException("Unsupported sync mode [{$mode}] for relation [{$relation}].");
    }
}
