<?php

namespace Aapolrac\AccessControl\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RoleGroupSync
{
    public static function attach(Model $user, Model|int $organization, array $groups): void
    {
        $orgId = $organization instanceof Model ? (int) $organization->getKey() : (int) $organization;

        $groupIds = static::resolveGroupIds($groups);

        if (empty($groupIds)) {
            return;
        }

        $alreadyAttached = $user->groups()
            ->wherePivot('organization_id', $orgId)
            ->pluck(static::groupTable().'.id')
            ->all();

        $toAttach = array_values(array_diff($groupIds, $alreadyAttached));

        if (empty($toAttach)) {
            return;
        }

        $payload = [];

        foreach ($toAttach as $groupId) {
            $payload[$groupId] = ['organization_id' => $orgId];
        }

        $user->groups()->attach($payload);
    }

    public static function syncDefaultsForRoles(Model $user, Model|int $organization, array $roleKeys): void
    {
        $orgId = $organization instanceof Model ? (int) $organization->getKey() : (int) $organization;
        $map = (array) config('access_control.groups', []);

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
            ->all();

        $desiredGroupIds = $groupModel::query()
            ->whereIn('key', $desiredGroupKeys)
            ->pluck('id')
            ->all();

        $currentManagedIds = DB::table(static::groupUserTable())
            ->where('user_id', $user->getKey())
            ->where('organization_id', $orgId)
            ->whereIn('group_id', $managedGroupIds)
            ->pluck('group_id')
            ->all();

        $toAttach = array_values(array_diff($desiredGroupIds, $currentManagedIds));
        $toDetach = array_values(array_diff($currentManagedIds, $desiredGroupIds));

        if (! empty($toAttach)) {
            $attach = [];

            foreach ($toAttach as $groupId) {
                $attach[$groupId] = ['organization_id' => $orgId];
            }

            $user->groups()->attach($attach);
        }

        if (! empty($toDetach)) {
            DB::table(static::groupUserTable())
                ->where('user_id', $user->getKey())
                ->where('organization_id', $orgId)
                ->whereIn('group_id', $toDetach)
                ->delete();
        }
    }

    public static function attachDefaultsForRole(Model $user, Model|int $organization, string|array $roleKey): void
    {
        $roleKeys = is_array($roleKey) ? $roleKey : [$roleKey];

        static::syncDefaultsForRoles($user, $organization, $roleKeys);
    }

    public static function roleToDefaultGroupsMap(): array
    {
        return (array) config('access_control.groups', []);
    }

    protected static function resolveGroupIds(array $groups): array
    {
        $groupModel = static::groupModel();
        $keys = array_values(array_filter($groups, static fn ($group) => is_string($group)));
        $ids = array_values(array_filter($groups, static fn ($group) => is_int($group)));

        $idsByKeys = empty($keys)
            ? []
            : $groupModel::query()->whereIn('key', $keys)->pluck('id')->all();

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
}
