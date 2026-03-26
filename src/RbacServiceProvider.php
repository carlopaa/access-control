<?php

namespace Aapolrac\Rbac;

use Aapolrac\Rbac\Commands\RbacCommand;
use Aapolrac\Rbac\Commands\SyncPermissionsCommand;
use Aapolrac\Rbac\Contracts\TenantResolver;
use Aapolrac\Rbac\Middleware\CheckPermission;
use Aapolrac\Rbac\Middleware\CheckRole;
use Aapolrac\Rbac\Support\DefaultTenantResolver;
use Aapolrac\Rbac\Support\GateRegistrar;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RbacServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('rbac')
            ->hasConfigFile()
            ->hasMigration('create_roles_table')
            ->hasMigration('create_groups_table')
            ->hasMigration('create_permissions_table')
            ->hasMigration('create_group_permission_table')
            ->hasMigration('create_role_user_table')
            ->hasMigration('create_group_user_table')
            ->hasCommand(RbacCommand::class)
            ->hasCommand(SyncPermissionsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(TenantResolver::class, DefaultTenantResolver::class);
    }

    public function packageBooted(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('rbac.permission', CheckPermission::class);
        $router->aliasMiddleware('rbac.role', CheckRole::class);

        $this->registerGates();
    }

    protected function registerGates(): void
    {
        // Register a catch-all Gate::before so any $user->can('some:permission') is
        // resolved through hasPermission() on models using the HasRbac trait.
        Gate::before(static function ($user, string $ability): ?bool {
            if (! method_exists($user, 'hasPermission')) {
                return null;
            }

            if ($user->hasPermission($ability)) {
                return true;
            }

            return null; // let other gates / policies continue
        });

        // Register explicit Gate abilities from configured enum classes.
        $enumClasses = (array) config('rbac.permissions.enum_classes', []);

        if (! empty($enumClasses)) {
            GateRegistrar::registerPermissions(
                array_merge(...array_map(
                    static fn (string $class) => enum_exists($class)
                        ? array_map(static fn ($c) => $c->value, $class::cases())
                        : [],
                    $enumClasses
                ))
            );
        }
    }
}
