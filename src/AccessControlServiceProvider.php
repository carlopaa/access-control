<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl;

use Aapolrac\AccessControl\Commands\AccessControlCommand;
use Aapolrac\AccessControl\Commands\SyncPermissionsCommand;
use Aapolrac\AccessControl\Contracts\OrganizationResolver;
use Aapolrac\AccessControl\Contracts\TenantResolver;
use Aapolrac\AccessControl\Middleware\CheckPermission;
use Aapolrac\AccessControl\Middleware\CheckRole;
use Aapolrac\AccessControl\Support\DefaultOrganizationResolver;
use Aapolrac\AccessControl\Support\DefaultTenantResolver;
use Aapolrac\AccessControl\Support\GateRegistrar;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AccessControlServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('access-control')
            ->hasConfigFile('access_control')
            ->hasMigration('create_roles_table')
            ->hasMigration('create_groups_table')
            ->hasMigration('create_permissions_table')
            ->hasMigration('create_group_permission_table')
            ->hasMigration('create_role_user_table')
            ->hasMigration('create_group_user_table')
            ->hasCommand(AccessControlCommand::class)
            ->hasCommand(SyncPermissionsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AccessControl::class, static fn (): AccessControl => new AccessControl);
        $this->app->singleton(OrganizationResolver::class, DefaultOrganizationResolver::class);
        $this->app->singleton(TenantResolver::class, static fn ($app): OrganizationResolver => $app->make(OrganizationResolver::class));
    }

    public function packageBooted(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('access.permission', CheckPermission::class);
        $router->aliasMiddleware('access.role', CheckRole::class);

        $this->registerGates();
    }

    protected function registerGates(): void
    {
        // Register a catch-all Gate::before so any $user->can('some:permission') is
        // resolved through hasPermission() on models using the HasAccessControl trait.
        Gate::before(static function ($user, string $ability): ?bool {
            if (! method_exists($user, 'hasPermission')) {
                return null;
            }

            if ($user->hasPermission($ability)) {
                return true;
            }

            return null;
        });

        $enumClasses = (array) config('access_control.permissions.enum_classes', []);

        if (! empty($enumClasses)) {
            GateRegistrar::registerPermissions(
                array_merge(...array_map(
                    static fn (string $class) => enum_exists($class)
                        ? array_values(array_filter(array_map(
                            static fn (\UnitEnum $case) => $case instanceof \BackedEnum ? $case->value : null,
                            $class::cases()
                        )))
                        : [],
                    $enumClasses
                ))
            );
        }
    }
}
