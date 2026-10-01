<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Concerns;

use Aapolrac\AccessControl\Contracts\OrganizationResolver;
use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Contracts\TenantResolver;
use Aapolrac\AccessControl\Support\DefaultScopeResolver;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;

/**
 * Scope-aware roles, groups, and permissions for an Eloquent model.
 *
 * Scope semantics (see README "Scoped vs global authorization"):
 *
 * - A role/group assignment whose scope column holds an id is a SCOPED grant. It
 *   participates only when that scope is the one being evaluated.
 * - A role or group assignment whose scope column is NULL is a GLOBAL grant. It
 *   participates in every scope and when no scope is active. Global grants are only
 *   ever created through the explicit *Global* helpers.
 * - Direct permissions stored on the model are GLOBAL.
 * - When a check is given no explicit scope, the bound ScopeResolver supplies the
 *   current scope. When it returns null, only GLOBAL grants are considered. A missing
 *   scope never widens a check to "any scope".
 */
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
        )->withPivot($this->scopeForeignKey())->withTimestamps();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            (string) config('access_control.models.group'),
            (string) config('access_control.tables.group_user', 'group_user'),
            'user_id',
            'group_id'
        )->withPivot($this->scopeForeignKey())->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Permission resolution
    |--------------------------------------------------------------------------
    */

    /**
     * All effective permission names for the given scope (or the current scope).
     *
     * Includes permissions from groups assigned in that scope, from global (NULL
     * scope) groups, and direct permissions. Group assignments from other scopes
     * are never included.
     */
    public function getAllPermissions(mixed $scope = null): Collection
    {
        $scopeId = $this->resolveScopeId($scope);

        $cached = $this->getCachedPermissions($scopeId);

        if ($cached !== null) {
            return collect($cached);
        }

        $groupPermissions = $this->constrainToScopeForRead($this->groups(), $scopeId)
            ->with('permissions')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name');

        $permissions = $groupPermissions
            ->merge($this->getDirectPermissions())
            ->map(static fn ($item) => strtolower((string) $item))
            ->unique()
            ->values();

        $this->cachePermissions($scopeId, $permissions->all());

        return $permissions;
    }

    /**
     * Direct permissions stored on the model. These are global: they apply in
     * every scope and when no scope is active.
     */
    public function getDirectPermissions(): Collection
    {
        return collect($this->permissions ?? [])
            ->map(fn ($permission) => $this->normalizePermission($permission))
            ->filter()
            ->unique()
            ->values();
    }

    public function hasPermission(BackedEnum|string $permission, mixed $scope = null): bool
    {
        $permissionValue = $this->normalizePermission($permission);
        $permissions = $this->getAllPermissions($scope);

        if ($this->isDeniedPermission($permissionValue)) {
            return $permissions->contains($permissionValue);
        }

        if ($permissions->contains($this->denyPermissionName($permissionValue))) {
            return false;
        }

        return $permissions->contains($permissionValue);
    }

    public function hasAnyPermission(array $permissions, mixed $scope = null): bool
    {
        return collect($permissions)
            ->contains(fn ($permission) => $this->hasPermission($permission, $scope));
    }

    /*
    |--------------------------------------------------------------------------
    | Direct permissions
    |--------------------------------------------------------------------------
    */

    public function assignPermission(BackedEnum|string $permission): static
    {
        return $this->assignPermissions([$permission]);
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

    /*
    |--------------------------------------------------------------------------
    | Role checks
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the model holds the role in the given scope (or the current scope).
     * Global (NULL scope) role assignments always count.
     */
    public function hasRole(BackedEnum|string $role, mixed $scope = null): bool
    {
        return $this->constrainToScopeForRead($this->roles(), $this->resolveScopeId($scope))
            ->where($this->roleTable().'.key', $this->normalizeEnumOrString($role))
            ->exists();
    }

    public function hasAnyRole(array $roles, mixed $scope = null): bool
    {
        $roleValues = array_map(fn ($role) => $this->normalizeEnumOrString($role), $roles);

        return $this->constrainToScopeForRead($this->roles(), $this->resolveScopeId($scope))
            ->whereIn($this->roleTable().'.key', $roleValues)
            ->exists();
    }

    public function hasRoleInScope(BackedEnum|string $role, int $scopeId): bool
    {
        return $this->hasRole($role, $scopeId);
    }

    public function hasAnyRoleInScope(array $roles, int $scopeId): bool
    {
        return $this->hasAnyRole($roles, $scopeId);
    }

    /**
     * Whether the model holds the role in ANY scope. This is a cross-scope query,
     * not an authorization check for the current scope.
     */
    public function hasRoleInAnyScope(BackedEnum|string $role): bool
    {
        return $this->roles()
            ->where($this->roleTable().'.key', $this->normalizeEnumOrString($role))
            ->exists();
    }

    public function hasAnyRoleInAnyScope(array $roles): bool
    {
        $roleValues = array_map(fn ($role) => $this->normalizeEnumOrString($role), $roles);

        return $this->roles()
            ->whereIn($this->roleTable().'.key', $roleValues)
            ->exists();
    }

    /** @deprecated Use hasRoleInScope() instead. */
    public function hasRoleInOrg(BackedEnum|string $role, int $organizationId): bool
    {
        return $this->hasRoleInScope($role, $organizationId);
    }

    /** @deprecated Use hasAnyRoleInScope() instead. */
    public function hasAnyRoleInOrg(array $roles, int $organizationId): bool
    {
        return $this->hasAnyRoleInScope($roles, $organizationId);
    }

    /*
    |--------------------------------------------------------------------------
    | Scoped role and group assignment
    |--------------------------------------------------------------------------
    */

    public function assignRole(BackedEnum|string|int $role, mixed $scope): static
    {
        return $this->assignRoles([$role], $scope);
    }

    public function assignRoleInScope(BackedEnum|string|int $role, mixed $scope): static
    {
        return $this->assignRole($role, $scope);
    }

    public function assignRoles(array $roles, mixed $scope): static
    {
        return $this->syncBelongsToManyInScope('roles', $this->resolveRoleIds($roles), $scope, 'attach');
    }

    public function assignRolesInScope(array $roles, mixed $scope): static
    {
        return $this->assignRoles($roles, $scope);
    }

    public function syncRoles(array $roles, mixed $scope): static
    {
        return $this->syncBelongsToManyInScope('roles', $this->resolveRoleIds($roles), $scope, 'sync');
    }

    public function syncRolesInScope(array $roles, mixed $scope): static
    {
        return $this->syncRoles($roles, $scope);
    }

    public function revokeRole(BackedEnum|string|int $role, mixed $scope): static
    {
        return $this->syncBelongsToManyInScope('roles', $this->resolveRoleIds([$role]), $scope, 'detach');
    }

    public function revokeRoleInScope(BackedEnum|string|int $role, mixed $scope): static
    {
        return $this->revokeRole($role, $scope);
    }

    public function assignGroup(BackedEnum|string|int $group, mixed $scope): static
    {
        return $this->assignGroups([$group], $scope);
    }

    public function assignGroupInScope(BackedEnum|string|int $group, mixed $scope): static
    {
        return $this->assignGroup($group, $scope);
    }

    public function assignGroups(array $groups, mixed $scope): static
    {
        return $this->syncBelongsToManyInScope('groups', $this->resolveGroupIds($groups), $scope, 'attach');
    }

    public function assignGroupsInScope(array $groups, mixed $scope): static
    {
        return $this->assignGroups($groups, $scope);
    }

    public function syncGroups(array $groups, mixed $scope): static
    {
        return $this->syncBelongsToManyInScope('groups', $this->resolveGroupIds($groups), $scope, 'sync');
    }

    public function syncGroupsInScope(array $groups, mixed $scope): static
    {
        return $this->syncGroups($groups, $scope);
    }

    public function revokeGroup(BackedEnum|string|int $group, mixed $scope): static
    {
        return $this->syncBelongsToManyInScope('groups', $this->resolveGroupIds([$group]), $scope, 'detach');
    }

    public function revokeGroupInScope(BackedEnum|string|int $group, mixed $scope): static
    {
        return $this->revokeGroup($group, $scope);
    }

    /*
    |--------------------------------------------------------------------------
    | Global (unscoped) role and group assignment
    |--------------------------------------------------------------------------
    |
    | Global assignments are stored with a NULL scope and apply in every scope
    | and when no scope is active. They must be requested explicitly; passing a
    | null scope to the scoped helpers above is an error, never an implicit
    | global grant.
    */

    public function assignGlobalRole(BackedEnum|string|int $role): static
    {
        return $this->assignGlobalRoles([$role]);
    }

    public function assignGlobalRoles(array $roles): static
    {
        return $this->syncBelongsToManyInScope('roles', $this->resolveRoleIds($roles), null, 'attach', global: true);
    }

    public function syncGlobalRoles(array $roles): static
    {
        return $this->syncBelongsToManyInScope('roles', $this->resolveRoleIds($roles), null, 'sync', global: true);
    }

    public function revokeGlobalRole(BackedEnum|string|int $role): static
    {
        return $this->revokeGlobalRoles([$role]);
    }

    public function revokeGlobalRoles(array $roles): static
    {
        return $this->syncBelongsToManyInScope('roles', $this->resolveRoleIds($roles), null, 'detach', global: true);
    }

    public function assignGlobalGroup(BackedEnum|string|int $group): static
    {
        return $this->assignGlobalGroups([$group]);
    }

    public function assignGlobalGroups(array $groups): static
    {
        return $this->syncBelongsToManyInScope('groups', $this->resolveGroupIds($groups), null, 'attach', global: true);
    }

    public function syncGlobalGroups(array $groups): static
    {
        return $this->syncBelongsToManyInScope('groups', $this->resolveGroupIds($groups), null, 'sync', global: true);
    }

    public function revokeGlobalGroup(BackedEnum|string|int $group): static
    {
        return $this->revokeGlobalGroups([$group]);
    }

    public function revokeGlobalGroups(array $groups): static
    {
        return $this->syncBelongsToManyInScope('groups', $this->resolveGroupIds($groups), null, 'detach', global: true);
    }

    /*
    |--------------------------------------------------------------------------
    | Query scopes
    |--------------------------------------------------------------------------
    */

    /** Users holding the role in ANY scope (cross-scope query). */
    public function scopeWithRole(Builder $query, BackedEnum|string $role): Builder
    {
        $roleValue = $this->normalizeEnumOrString($role);
        $roleTable = $this->roleTable();

        return $query->whereHas('roles', function ($builder) use ($roleTable, $roleValue) {
            $builder->where($roleTable.'.key', $roleValue);
        });
    }

    /** Users holding any of the roles in ANY scope (cross-scope query). */
    public function scopeWithAnyRoles(Builder $query, array $roles): Builder
    {
        $roleValues = array_map(fn ($role) => $this->normalizeEnumOrString($role), $roles);
        $roleTable = $this->roleTable();

        return $query->whereHas('roles', function ($builder) use ($roleTable, $roleValues) {
            $builder->whereIn($roleTable.'.key', $roleValues);
        });
    }

    /**
     * Users holding the role in the given scope (or the current scope). When no
     * scope can be resolved, only global (NULL scope) role rows match.
     */
    public function scopeWithRoleInScope(Builder $query, BackedEnum|string $role, mixed $scope = null): Builder
    {
        $roleValue = $this->normalizeEnumOrString($role);
        $scopeId = $this->resolveScopeId($scope);
        $roleTable = $this->roleTable();
        $scopeColumn = (string) config('access_control.tables.role_user', 'role_user').'.'.$this->scopeForeignKey();

        return $query->whereHas('roles', function ($builder) use ($roleTable, $roleValue, $scopeColumn, $scopeId) {
            $builder->where($roleTable.'.key', $roleValue);

            $this->applyScopeColumnConstraint($builder, $scopeColumn, $scopeId);
        });
    }

    public function scopeWithAnyRolesInScope(Builder $query, array $roles, mixed $scope = null): Builder
    {
        $roleValues = array_map(fn ($role) => $this->normalizeEnumOrString($role), $roles);
        $scopeId = $this->resolveScopeId($scope);
        $roleTable = $this->roleTable();
        $scopeColumn = (string) config('access_control.tables.role_user', 'role_user').'.'.$this->scopeForeignKey();

        return $query->whereHas('roles', function ($builder) use ($roleTable, $roleValues, $scopeColumn, $scopeId) {
            $builder->whereIn($roleTable.'.key', $roleValues);

            $this->applyScopeColumnConstraint($builder, $scopeColumn, $scopeId);
        });
    }

    /** @deprecated Use withRoleInScope() instead. */
    public function scopeWithRoleInOrg(Builder $query, BackedEnum|string $role, mixed $organization = null): Builder
    {
        return $this->scopeWithRoleInScope($query, $role, $organization);
    }

    /** @deprecated Use withAnyRolesInScope() instead. */
    public function scopeWithAnyRolesInOrg(Builder $query, array $roles, mixed $organization = null): Builder
    {
        return $this->scopeWithAnyRolesInScope($query, $roles, $organization);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission cache (request-lifetime memo in Laravel Context)
    |--------------------------------------------------------------------------
    |
    | Resolved permission lists are memoised per (model, scope). The hidden
    | Context key contains the model class and primary key; the value is a map of
    | scope token => permission names. Only the authenticated model is memoised so
    | the hidden context stays small (Laravel copies it into queued job payloads).
    */

    /**
     * Forget every memoised permission list for this model, in all scopes.
     */
    public function flushPermissionCache(): static
    {
        if ($this->permissionCacheEnabled() && $this->getKey() !== null) {
            Context::forgetHidden($this->permissionCacheKey());
        }

        return $this;
    }

    protected function forgetPermissionContextCache(): void
    {
        $this->flushPermissionCache();
    }

    protected function permissionCacheEnabled(): bool
    {
        return (bool) config('access_control.context_cache.enabled', true);
    }

    protected function permissionCacheKey(): string
    {
        $prefix = (string) config('access_control.context_cache.key', 'permissions');

        return $prefix.':'.static::class.':'.(string) $this->getKey();
    }

    protected function permissionCacheScopeToken(?int $scopeId): string
    {
        return $scopeId === null ? 'global' : 'scope:'.$scopeId;
    }

    /**
     * Memoisation is limited to the currently authenticated model instance.
     */
    protected function shouldUsePermissionCache(): bool
    {
        if (! $this->permissionCacheEnabled() || $this->getKey() === null) {
            return false;
        }

        $authenticated = Auth::user();

        return $authenticated instanceof Model && $authenticated->is($this);
    }

    /**
     * @return array<int, string>|null
     */
    protected function getCachedPermissions(?int $scopeId): ?array
    {
        if (! $this->shouldUsePermissionCache()) {
            return null;
        }

        $bucket = Context::getHidden($this->permissionCacheKey());

        if (! is_array($bucket)) {
            return null;
        }

        $token = $this->permissionCacheScopeToken($scopeId);

        return array_key_exists($token, $bucket) && is_array($bucket[$token]) ? $bucket[$token] : null;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function cachePermissions(?int $scopeId, array $permissions): void
    {
        if (! $this->shouldUsePermissionCache()) {
            return;
        }

        $key = $this->permissionCacheKey();
        $bucket = Context::getHidden($key);

        if (! is_array($bucket)) {
            $bucket = [];
        }

        $bucket[$this->permissionCacheScopeToken($scopeId)] = $permissions;

        Context::addHidden($key, $bucket);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve a scope argument to an id. Null defers to the bound ScopeResolver,
     * which may itself return null when no scope is active.
     */
    protected function resolveScopeId(mixed $scope = null): ?int
    {
        if ($scope !== null) {
            if (is_object($scope) && method_exists($scope, 'getKey')) {
                return (int) $scope->getKey();
            }

            return (int) $scope;
        }

        return $this->resolveScopeResolver()->resolveScopeId();
    }

    /** @deprecated Use resolveScopeId() instead. */
    protected function resolveOrganizationId(mixed $organization = null): ?int
    {
        return $this->resolveScopeId($organization);
    }

    protected function scopeForeignKey(): string
    {
        return (string) config('access_control.scope.foreign_key', 'organization_id');
    }

    protected function roleTable(): string
    {
        return (string) config('access_control.tables.roles', 'roles');
    }

    /**
     * Constrain a pivot relation for READS: rows in the given scope plus global
     * (NULL scope) rows. With no scope, only global rows.
     */
    protected function constrainToScopeForRead(BelongsToMany $relation, ?int $scopeId): BelongsToMany
    {
        $column = $relation->qualifyPivotColumn($this->scopeForeignKey());

        if ($scopeId === null) {
            return $relation->wherePivotNull($this->scopeForeignKey());
        }

        return $relation->where(function ($query) use ($column, $scopeId): void {
            $query->where($column, $scopeId)->orWhereNull($column);
        });
    }

    /**
     * Constrain a pivot relation for WRITES to exactly one scope token so that
     * Eloquent's attach/sync/detach never touch rows belonging to another scope.
     */
    protected function constrainToScopeForWrite(BelongsToMany $relation, ?int $scopeId): BelongsToMany
    {
        return $scopeId === null
            ? $relation->wherePivotNull($this->scopeForeignKey())
            : $relation->wherePivot($this->scopeForeignKey(), $scopeId);
    }

    protected function applyScopeColumnConstraint(Builder $builder, string $column, ?int $scopeId): void
    {
        if ($scopeId === null) {
            $builder->whereNull($column);

            return;
        }

        $builder->where(function ($query) use ($column, $scopeId): void {
            $query->where($column, $scopeId)->orWhereNull($column);
        });
    }

    protected function resolveScopeResolver(): ScopeResolver
    {
        $scopeResolver = app(ScopeResolver::class);

        if (! $scopeResolver instanceof DefaultScopeResolver) {
            return $scopeResolver;
        }

        $organizationResolver = app(OrganizationResolver::class);

        if (! $organizationResolver instanceof DefaultScopeResolver) {
            return new class($organizationResolver) implements ScopeResolver
            {
                public function __construct(private readonly OrganizationResolver $resolver) {}

                public function resolveScopeId(?Model $scope = null): ?int
                {
                    return $this->resolver->resolveOrganizationId($scope);
                }
            };
        }

        $tenantResolver = app(TenantResolver::class);

        if (! $tenantResolver instanceof DefaultScopeResolver) {
            return new class($tenantResolver) implements ScopeResolver
            {
                public function __construct(private readonly TenantResolver $resolver) {}

                public function resolveScopeId(?Model $scope = null): ?int
                {
                    return $this->resolver->resolveOrganizationId($scope);
                }
            };
        }

        return $scopeResolver;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

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

    protected function resolveRoleIds(array $roles): array
    {
        return $this->resolveRelationIds($roles, (string) config('access_control.models.role'));
    }

    protected function resolveGroupIds(array $groups): array
    {
        return $this->resolveRelationIds($groups, (string) config('access_control.models.group'));
    }

    protected function resolveRelationIds(array $values, string $modelClass): array
    {
        $normalizedValues = collect($values)
            ->map(static fn ($value) => $value instanceof BackedEnum ? $value->value : $value)
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

    /**
     * Attach, sync, or detach pivot rows for exactly one scope token. Rows that
     * belong to other scopes are never read, moved, or deleted.
     */
    protected function syncBelongsToManyInScope(
        string $relation,
        array $ids,
        mixed $scope,
        string $mode,
        bool $global = false
    ): static {
        $scopeId = $global ? null : $this->resolveScopeId($scope);

        if (! $global && $scopeId === null) {
            throw new InvalidArgumentException(
                'A scope is required for role and group assignments. Use the global role and group helpers to grant access in every scope.'
            );
        }

        $relatedIds = array_values(array_unique(array_map('intval', $ids)));

        /** @var BelongsToMany $relationQuery */
        $relationQuery = $this->constrainToScopeForWrite($this->{$relation}(), $scopeId);

        $payload = [];

        foreach ($relatedIds as $id) {
            $payload[$id] = [$this->scopeForeignKey() => $scopeId];
        }

        match ($mode) {
            'attach' => $payload !== [] ? $relationQuery->syncWithoutDetaching($payload) : null,
            'sync' => $relationQuery->sync($payload),
            'detach' => $relatedIds !== [] ? $relationQuery->detach($relatedIds) : null,
            default => throw new InvalidArgumentException("Unsupported sync mode [{$mode}] for relation [{$relation}]."),
        };

        $this->flushPermissionCache();

        return $this;
    }
}
