<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl;

use Aapolrac\AccessControl\Commands\AccessControlCommand;
use Aapolrac\AccessControl\Commands\InstallAccessControlCommand;
use Aapolrac\AccessControl\Commands\MakePermissionsEnumCommand;
use Aapolrac\AccessControl\Commands\SyncPermissionsCommand;
use Aapolrac\AccessControl\Contracts\OrganizationResolver;
use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Contracts\TenantResolver;
use Aapolrac\AccessControl\Middleware\CheckPermission;
use Aapolrac\AccessControl\Middleware\CheckRole;
use Aapolrac\AccessControl\Support\DefaultScopeResolver;
use Aapolrac\AccessControl\Support\GateAbilityResolver;
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
            ->hasMigration('make_role_user_scope_nullable')
            ->hasCommand(AccessControlCommand::class)
            ->hasCommand(InstallAccessControlCommand::class)
            ->hasCommand(MakePermissionsEnumCommand::class)
            ->hasCommand(SyncPermissionsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AccessControl::class, static fn (): AccessControl => new AccessControl);
        $this->app->singleton(DefaultScopeResolver::class, DefaultScopeResolver::class);
        $this->app->singleton(ScopeResolver::class, static fn ($app): DefaultScopeResolver => $app->make(DefaultScopeResolver::class));
        $this->app->singleton(OrganizationResolver::class, static fn ($app): DefaultScopeResolver => $app->make(DefaultScopeResolver::class));
        $this->app->singleton(TenantResolver::class, static fn ($app): DefaultScopeResolver => $app->make(DefaultScopeResolver::class));
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
        // Catch-all Gate::before hook. Abilities are resolved through hasPermission() on
        // models using HasAccessControl. The hook receives the ability arguments and
        // abstains whenever a policy or a defined ability can handle them, so
        // `$user->can('posts.update', $post)` reaches the policy with $post intact.
        Gate::before(static function ($user, string $ability, array $arguments = []): ?bool {
            /** @var GateAbilityResolver $resolver */
            $resolver = app(GateAbilityResolver::class);

            return $resolver($user, $ability, $arguments);
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
