<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Config-driven role → default group maintenance.
 *
 * The scoped methods operate only on pivot rows belonging to the given scope.
 * The global methods operate only on rows with a NULL scope. Neither ever reads,
 * attaches, or detaches rows that belong to the other, so scope boundaries are
 * preserved. Passing null to a scoped method is a type error, never a global grant.
 */
class RoleGroupSync
{
    public static function attach(Model $user, Model|int $scope, array $groups): void
    {
        static::attachInScope($user, static::scopeId($scope), $groups);
    }

    /**
     * Attach groups globally (NULL scope): they apply in every scope and with no scope.
     */
    public static function attachGlobal(Model $user, array $groups): void
    {
        static::attachInScope($user, null, $groups);
    }

    public static function syncDefaultsForRoles(Model $user, Model|int $scope, array $roleKeys): void
    {
        static::syncDefaultsInScope($user, static::scopeId($scope), $roleKeys);
    }

    /**
     * Maintain the configured default groups for global roles. Only global (NULL
     * scope) group rows are managed; scoped rows are untouched.
     */
    public static function syncGlobalDefaultsForRoles(Model $user, array $roleKeys): void
    {
        static::syncDefaultsInScope($user, null, $roleKeys);
    }

    public static function attachDefaultsForRole(Model $user, Model|int $scope, string|array $roleKey): void
    {
        $roleKeys = is_array($roleKey) ? $roleKey : [$roleKey];

        static::syncDefaultsForRoles($user, $scope, $roleKeys);
    }

    public static function roleToDefaultGroupsMap(): array
    {
        return (array) config('access_control.groups', []);
    }

    protected static function attachInScope(Model $user, ?int $scopeId, array $groups): void
    {
        $scopeForeignKey = static::scopeForeignKey();
        $groupIds = static::resolveGroupIds($groups);

        if (empty($groupIds)) {
            return;
        }

        $alreadyAttached = static::pivotQueryForScope($user, $scopeId)
            ->pluck('group_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $toAttach = array_values(array_diff($groupIds, $alreadyAttached));

        if (empty($toAttach)) {
            return;
        }

        $payload = [];

        foreach ($toAttach as $groupId) {
            $payload[$groupId] = [$scopeForeignKey => $scopeId];
        }

        /** @phpstan-ignore method.notFound */
        $user->groups()->attach($payload);

        static::flushPermissionCache($user);
    }

    protected static function syncDefaultsInScope(Model $user, ?int $scopeId, array $roleKeys): void
    {
        $scopeForeignKey = static::scopeForeignKey();
        $map = static::roleToDefaultGroupsMap();

        $managedGroupKeys = collect($map)->flatten()->unique()->values();
        $desiredGroupKeys = collect($roleKeys)
            ->filter()
            ->flatMap(fn ($roleKey) => $map[$roleKey] ?? [])
            ->unique()
            ->values();

        $groupModel = static::groupModel();

        $managedGroupIds = $groupModel::query()
            ->whereIn('key', $managedGroupKeys)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $desiredGroupIds = $groupModel::query()
            ->whereIn('key', $desiredGroupKeys)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $currentManagedIds = static::pivotQueryForScope($user, $scopeId)
            ->whereIn('group_id', $managedGroupIds)
            ->pluck('group_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $toAttach = array_values(array_diff($desiredGroupIds, $currentManagedIds));
        $toDetach = array_values(array_diff($currentManagedIds, $desiredGroupIds));

        if (! empty($toAttach)) {
            $attach = [];

            foreach ($toAttach as $groupId) {
                $attach[$groupId] = [$scopeForeignKey => $scopeId];
            }

            /** @phpstan-ignore method.notFound */
            $user->groups()->attach($attach);
        }

        if (! empty($toDetach)) {
            static::pivotQueryForScope($user, $scopeId)
                ->whereIn('group_id', $toDetach)
                ->delete();
        }

        if (! empty($toAttach) || ! empty($toDetach)) {
            static::flushPermissionCache($user);
        }
    }

    /**
     * Group pivot rows of the user for exactly one scope token (id or NULL).
     */
    protected static function pivotQueryForScope(Model $user, ?int $scopeId): Builder
    {
        $query = DB::table(static::groupUserTable())->where('user_id', $user->getKey());

        return $scopeId === null
            ? $query->whereNull(static::scopeForeignKey())
            : $query->where(static::scopeForeignKey(), $scopeId);
    }

    protected static function scopeId(Model|int $scope): int
    {
        return $scope instanceof Model ? (int) $scope->getKey() : $scope;
    }

    protected static function flushPermissionCache(Model $user): void
    {
        if (method_exists($user, 'flushPermissionCache')) {
            $user->flushPermissionCache();
        }
    }

    protected static function resolveGroupIds(array $groups): array
    {
        $groupModel = static::groupModel();
        $keys = array_values(array_filter($groups, static fn ($group) => is_string($group)));
        $ids = array_values(array_filter($groups, static fn ($group) => is_int($group)));

        $idsByKeys = empty($keys)
            ? []
            : $groupModel::query()->whereIn('key', $keys)->pluck('id')->map(static fn ($id) => (int) $id)->all();

        return array_values(array_unique([...$ids, ...$idsByKeys]));
    }

    protected static function groupModel(): string
    {
        return (string) config('access_control.models.group');
    }

    protected static function groupTable(): string
    {
        return (string) config('access_control.tables.groups', 'groups');
    }

    protected static function groupUserTable(): string
    {
        return (string) config('access_control.tables.group_user', 'group_user');
    }

    protected static function scopeForeignKey(): string
    {
        return (string) config('access_control.scope.foreign_key', 'organization_id');
    }
}
