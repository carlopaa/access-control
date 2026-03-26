<?php

namespace Aapolrac\AccessControl\Support;

use Illuminate\Support\Facades\Gate;

class GateRegistrar
{
    public static function registerPermissions(string|array $source): void
    {
        $permissions = is_array($source) ? $source : static::extractFromEnum($source);

        foreach ($permissions as $permission) {
            $permissionValue = strtolower((string) $permission);

            if (Gate::has($permissionValue)) {
                continue;
            }

            Gate::define($permissionValue, static function ($user) use ($permissionValue): bool {
                if (! method_exists($user, 'hasPermission')) {
                    return false;
                }

                return (bool) $user->hasPermission($permissionValue);
            });
        }
    }

    public static function registerRoles(string|array $source): void
    {
        $roles = is_array($source) ? $source : static::extractFromEnum($source);

        foreach ($roles as $role) {
            $roleValue = strtolower((string) $role);
            $gateKey = 'role:'.$roleValue;

            if (Gate::has($gateKey)) {
                continue;
            }

            Gate::define($gateKey, static function ($user) use ($roleValue): bool {
                if (! method_exists($user, 'hasRole')) {
                    return false;
                }

                return (bool) $user->hasRole($roleValue);
            });
        }
    }

    protected static function extractFromEnum(string $enumClass): array
    {
        if (! enum_exists($enumClass)) {
            return [];
        }

        return array_map(
            static fn ($case) => (string) $case->value,
            $enumClass::cases()
        );
    }
}
