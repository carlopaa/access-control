# Access control for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/carlopaa/access-control.svg?style=flat-square)](https://packagist.org/packages/carlopaa/access-control)
[![Total Downloads](https://img.shields.io/packagist/dt/carlopaa/access-control.svg?style=flat-square)](https://packagist.org/packages/carlopaa/access-control)

`carlopaa/access-control` is a generic, scope-aware RBAC package for Laravel.

It combines role-based and permission-based patterns:

- groups aggregate permissions
- users receive groups, either globally or inside a scope
- users receive roles, either globally or inside a scope; roles can map to default groups
- users can also receive direct (global) permissions
- `:deny` permissions override allows

A **scope** is whatever your application says it is: an organization, a workspace, a team, a project, or nothing at all. The package never assumes a particular scope model, and applications that do not use scopes can use the package without configuring one.

The package works from controllers, services, policies, middleware, Livewire components, console commands, jobs, and tests. It does not depend on Filament, Livewire, or HTTP request state.

## Table of contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Required model setup](#required-model-setup)
- [Configuration reference](#configuration-reference)
- [Commands](#commands)
- [Authorization model](#authorization-model)
- [Using permissions and roles](#using-permissions-and-roles)
- [Role to default group sync](#role-to-default-group-sync)
- [Middleware](#middleware)
- [Gate integration](#gate-integration)
- [Scope resolution](#scope-resolution)
- [Caching](#caching)
- [Extension points](#extension-points)
- [Filament and other integrations](#filament-and-other-integrations)
- [Upgrading](#upgrading)
- [Testing](#testing)

## Installation

Install via Composer:

```bash
composer require carlopaa/access-control
```

Publish migrations and run them:

```bash
php artisan vendor:publish --tag="access-control-migrations"
php artisan migrate
```

Publish config:

```bash
php artisan vendor:publish --tag="access-control-config"
```

## Quick start

1. Add the trait to your user model:

```php
use Aapolrac\AccessControl\Concerns\HasAccessControl;

class User extends Authenticatable
{
    use HasAccessControl;
}
```

2. Add a JSON `permissions` column to users (for direct permissions):

```php
Schema::table('users', function (Blueprint $table) {
    $table->json('permissions')->nullable();
});
```

`HasAccessControl` automatically casts `permissions` to an array, so you do not need to add a separate cast on the user model.

3. Configure `config/access_control.php` (models, enum classes, groups, scope).

4. Seed permissions from your enums:

```bash
php artisan access-control:sync
```

5. Use checks in code:

```php
// Application without scopes
$user->assignGlobalRole('admin');
$user->assignGlobalGroup('staff');
$user->hasPermission(ReportPermission::ALLOW_VIEW);

// Application with scopes (organization, workspace, team, ...)
$user->assignRole('owner', $workspace);
$user->assignGroup('editors', $workspace);
$user->hasPermission(PostPermission::ALLOW_UPDATE, $workspace);
```

## Required model setup

The trait provides default `roles()` and `groups()` relations on the user model.
Only add your own methods if you want to customize those relationships.

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

public function roles(): BelongsToMany
{
    return $this->belongsToMany(
        Role::class,
        config('access_control.tables.role_user', 'role_user')
    )->withPivot(config('access_control.scope.foreign_key', 'organization_id'))->withTimestamps();
}

public function groups(): BelongsToMany
{
    return $this->belongsToMany(
        Group::class,
        config('access_control.tables.group_user', 'group_user')
    )->withPivot(config('access_control.scope.foreign_key', 'organization_id'))->withTimestamps();
}
```

Your `Group` model should have a permissions relation:

```php
public function permissions(): BelongsToMany
{
    return $this->belongsToMany(
        Permission::class,
        config('access_control.tables.group_permission', 'group_permission')
    )->withTimestamps();
}
```

## Configuration reference

`config/access_control.php`:

```php
return [
    'models' => [
        'role' => Aapolrac\AccessControl\Models\Role::class,
        'group' => Aapolrac\AccessControl\Models\Group::class,
        'permission' => Aapolrac\AccessControl\Models\Permission::class,
    ],

    'tables' => [
        'roles' => 'roles',
        'groups' => 'groups',
        'permissions' => 'permissions',
        'role_user' => 'role_user',
        'group_user' => 'group_user',
        'group_permission' => 'group_permission',
    ],

    'context_cache' => [
        'enabled' => true,
        'key' => 'permissions',
    ],

    'scope' => [
        'model' => App\Models\Workspace::class, // informational; used by access-control:install
        'foreign_key' => 'workspace_id',        // scope column on the role/group pivot tables
    ],

    'permissions' => [
        'enum_classes' => [
            App\Enums\MemberPermission::class,
            App\Enums\CustomerPermission::class,
        ],
    ],

    'groups' => [
        // role key => default group keys
        'owner' => ['owners', 'team-management'],
        'manager' => ['team-management'],
    ],
];
```

By default, the package ships with these models:

- `Aapolrac\AccessControl\Models\Role`
- `Aapolrac\AccessControl\Models\Group`
- `Aapolrac\AccessControl\Models\Permission`

In your app, you can override them in `models` with your own Eloquent classes.

Example override:

```php
'models' => [
    'role' => App\Models\Role::class,
    'group' => App\Models\Group::class,
    'permission' => App\Models\Permission::class,
],
```

## Commands

Check package installation:

```bash
php artisan access-control
```

Install config and migrations with a guided setup:

```bash
php artisan access-control:install
```

Sync permission records from configured enum classes:

```bash
php artisan access-control:sync
php artisan access-control:sync --only-missing
```

Generate a permission enum for a resource:

```bash
php artisan access-control:make-enum CustomerPermission --resource=customer
php artisan access-control:make-enum CustomerPermission --resource=customer --deny
```

By default the command writes to `app/Enums`, appends `Permission` if needed, and generates:

- allow cases for `view`, `create`, `update`, `delete`
- allow cases for `view-any`, `create-any`, `update-any`, `delete-any`
- optional deny cases when `--deny` is provided

## Authorization model

Permissions reach a user through two paths:

```text
User ──(global)──────────────► direct permissions
User ──(global or scoped)────► Group ──► Permission
User ──(global or scoped)────► Role   (labels; map to default groups via config)
```

Roles do not carry permissions themselves. They are labels you can check and that `RoleGroupSync` can translate into default groups.

### Scoped vs global grants

Every role and group assignment is stored on a pivot row with a scope column (`scope.foreign_key`, `organization_id` by default). The value of that column decides where the grant applies:

| Grant | Stored as | Applies in |
| --- | --- | --- |
| Scoped group membership | `scope column = <id>` | that scope only |
| Global group membership | `scope column = NULL` | every scope and when no scope is active |
| Scoped role | `scope column = <id>` | that scope only |
| Global role | `scope column = NULL` | every scope and when no scope is active |
| Direct permission | JSON on the user | every scope and when no scope is active |

`NULL` in the scope column has exactly one meaning: a global grant. The package never writes `NULL` from a scoped helper; only the `*Global*` helpers do. The resulting matrix, verified by the test suite:

| Grant | No scope | Scope A | Scope B |
| --- | --- | --- | --- |
| Direct permission | yes | yes | yes |
| Global group | yes | yes | yes |
| Global role | yes | yes | yes |
| Group in scope A | no | yes | no |
| Role in scope A | no | yes | no |

### Which scope is evaluated

Every check accepts an optional explicit scope (an id or a model with a key). When no scope is passed, the bound [`ScopeResolver`](#scope-resolution) supplies the current scope.

| Evaluated scope | Grants considered |
| --- | --- |
| A specific scope `S` | scoped grants for `S` + global grants |
| No scope (resolver returns `null`, or nothing is bound) | global grants only |

Two consequences follow, and both are covered by the test suite:

- A grant in scope A never satisfies a check for scope B.
- A missing scope never widens a check. If a user only holds a permission inside scope A and no scope is active, the check fails. Scoped grants are never treated as global by accident.

Global grants are always explicit. The scoped assignment helpers refuse a `null` scope with an `InvalidArgumentException`; use the `*GlobalRole*` and `*GlobalGroup*` helpers to grant access everywhere.

### Non-scoped applications

Applications without scopes do not need a resolver. Use global roles, global groups, and direct permissions:

```php
$user->assignGlobalRole('admin');
$user->assignGlobalGroups(['staff', 'reports']);
$user->assignPermission('billing:manage');

RoleGroupSync::syncGlobalDefaultsForRoles($user, ['admin']); // optional role → group defaults

$user->hasRole('admin');            // true
$user->hasPermission('reports:view'); // true if a global group or a direct permission grants it
```

Nothing else is required: the default resolver returns `null`, which means "no scope", and global grants are exactly what a non-scoped application needs.

## Using permissions and roles

### Permission checks

```php
$user->hasPermission(MemberPermission::ALLOW_VIEW_ANY);            // current scope
$user->hasPermission(MemberPermission::ALLOW_VIEW_ANY, $workspace); // explicit scope (model or id)
$user->hasAnyPermission([
    MemberPermission::ALLOW_VIEW_ANY,
    MemberPermission::ALLOW_UPDATE,
], $workspace);

$user->getAllPermissions();           // Collection of names effective in the current scope
$user->getAllPermissions($workspace); // ... in an explicit scope
```

### Deny override

If a user has `member:view-any:deny`, then `hasPermission('member:view-any')` returns `false` even if the allow exists from groups or direct permissions. A deny follows the same scope rules as any other permission: a deny granted in scope A only applies in scope A, while a deny from a global group or a direct permission applies everywhere.

### Direct permission API

Direct permissions are global. They are stored on the user and apply in every scope.

```php
$user->assignPermission(CustomerPermission::ALLOW_VIEW_ANY);
$user->assignPermissions([MemberPermission::ALLOW_VIEW_ANY, MemberPermission::ALLOW_UPDATE]);
$user->revokePermission(MemberPermission::ALLOW_UPDATE);
$user->syncDirectPermissions([MemberPermission::ALLOW_VIEW_ANY]);
$user->clearDirectPermissions();
$direct = $user->getDirectPermissions(); // Collection
```

### Role checks

```php
$user->hasRole('owner');                               // current scope
$user->hasRole('owner', $workspace);                   // explicit scope
$user->hasAnyRole(['owner', 'manager'], $workspace);
$user->hasRoleInScope('owner', $scopeId);
$user->hasAnyRoleInScope(['owner', 'manager'], $scopeId);

// Cross-scope queries. These answer "does the user hold this role anywhere?"
// and are not authorization checks for the current scope.
$user->hasRoleInAnyScope('owner');
$user->hasAnyRoleInAnyScope(['owner', 'manager']);
```

`hasRoleInOrg()` and `hasAnyRoleInOrg()` remain as deprecated aliases of the `InScope` variants.

### Role and group assignment

```php
$user->assignRole('owner', $scopeId);
$user->assignRoles(['owner', 'manager'], $scopeId);
$user->syncRoles(['manager'], $scopeId);
$user->revokeRole('owner', $scopeId);

$user->assignGroup('team-management', $scopeId);
$user->assignGroups(['team-management', 'reviewers'], $scopeId);
$user->syncGroups(['reviewers'], $scopeId);
$user->revokeGroup('team-management', $scopeId);
```

These helpers accept ids, keys, or a scope model instance. They only ever read, add, or remove pivot rows belonging to the given scope: assigning a group in scope B leaves the same group's membership in scope A untouched, and `syncGroups()` only detaches rows in the scope it was given.

Global role and group membership have their own, explicit helpers. They only read, add, or remove rows whose scope is `NULL`:

```php
$user->assignGlobalRole('admin');
$user->assignGlobalRoles(['admin', 'auditor']);
$user->syncGlobalRoles(['admin']);
$user->revokeGlobalRole('auditor');
$user->revokeGlobalRoles(['admin', 'auditor']);

$user->assignGlobalGroup('staff');
$user->assignGlobalGroups(['staff', 'reports']);
$user->syncGlobalGroups(['staff']);
$user->revokeGlobalGroup('reports');
$user->revokeGlobalGroups(['staff', 'reports']);
```

### Query scopes

```php
// Cross-scope: users holding the role in any scope
User::query()->withRole('owner')->get();
User::query()->withAnyRoles(['owner', 'manager'])->get();

// Scoped: users holding the role in the given scope (or the current scope when omitted)
User::query()->withRoleInScope('owner', $scopeId)->get();
User::query()->withAnyRolesInScope(['owner', 'manager'], $scopeId)->get();
```

When `withRoleInScope()` is called without a scope and no scope can be resolved, only global (`NULL` scope) role rows match. It no longer falls back to a cross-scope query.

## Role to default group sync

Use `RoleGroupSync` when role assignment should automatically maintain configured default groups:

```php
use Aapolrac\AccessControl\Support\RoleGroupSync;

RoleGroupSync::syncDefaultsForRoles($user, $scopeId, ['owner', 'manager']);
```

You can also attach explicit groups by key or id:

```php
RoleGroupSync::attach($user, $scopeId, ['team-management', 5]);
```

For global roles, maintain global default groups instead:

```php
RoleGroupSync::syncGlobalDefaultsForRoles($user, ['admin']);
RoleGroupSync::attachGlobal($user, ['staff']);
```

All four operations are idempotent. The scoped ones only touch group rows in the given scope; the global ones only touch rows with a `NULL` scope. Neither ever reads or removes the other's rows.

## Troubleshooting

### `vendor:publish --tag="access-control-config"` not available

If the tag is not found, run:

```bash
composer update carlopaa/access-control -W
php artisan package:discover --ansi
php artisan vendor:publish --provider="Aapolrac\\AccessControl\\AccessControlServiceProvider" --tag="access-control-config"
```

If `config/access_control.php` already exists, Laravel will skip it. Use force when you want to overwrite:

```bash
php artisan vendor:publish --tag="access-control-config" --force
```

## Middleware

The package auto-registers middleware aliases:

- `access.permission`
- `access.role`

Usage:

```php
Route::middleware(['auth', 'access.permission:member:view-any'])->group(function () {
    // ...
});

Route::middleware(['auth', 'access.role:owner,manager'])->group(function () {
    // ...
});
```

The middleware calls `hasAnyPermission()` / `hasAnyRole()` on the authenticated user, so it follows exactly the same scope rules as direct checks: grants are evaluated in the scope supplied by your `ScopeResolver`, and a missing scope only sees global grants. Unauthenticated requests receive `401`, unauthorized ones `403`.

## Gate integration

The package registers a `Gate::before` hook that answers abilities with package permissions whenever the user model has `hasPermission()`:

```php
$user->can(MemberPermission::ALLOW_VIEW_ANY);
Gate::allows(MemberPermission::ALLOW_VIEW_ANY->value);
```

The hook follows these rules:

- **Ability without arguments.** If the user holds the permission (in the current scope), the ability is granted. Otherwise the hook abstains and Laravel continues with its own resolution.
- **Ability with arguments**, such as `$user->can('update', $post)`. If a policy for the first argument can handle the ability, or the ability has been defined with `Gate::define()`, the hook abstains. The ability name and all of its arguments reach that policy or callback untouched. The package never bypasses a policy just because the user holds a permission with the same name.
- **Ability with arguments that nothing else handles.** The package falls back to the permission check in the current scope.
- The hook never returns `false`, so it cannot deny what a policy would allow.

This keeps policies as the place where a target's own scope participates:

```php
class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        // Evaluate the permission in the post's scope, not the current one.
        return $user->hasPermission(PostPermission::ALLOW_UPDATE, $post->workspace_id);
    }
}
```

Additionally, enum-based permission abilities can be auto-registered from `permissions.enum_classes` via `GateRegistrar`. Those defined abilities also resolve through `hasPermission()`.

## Scope resolution

The package asks a single contract for the current scope:

```php
namespace Aapolrac\AccessControl\Contracts;

interface ScopeResolver
{
    public function resolveScopeId(?Model $scope = null): ?int;
}
```

Bind your own implementation to make checks without an explicit scope use your application's notion of "current":

```php
use Aapolrac\AccessControl\Contracts\ScopeResolver;

app()->singleton(ScopeResolver::class, CurrentWorkspaceResolver::class);
```

```php
class CurrentWorkspaceResolver implements ScopeResolver
{
    public function resolveScopeId(?Model $scope = null): ?int
    {
        if ($scope !== null) {
            return (int) $scope->getKey();
        }

        // Anything that fits your application: a container binding set by middleware,
        // a session value, a job property, a console option, ...
        return app()->bound('current.workspace') ? (int) app('current.workspace')->getKey() : null;
    }
}
```

Guidelines:

- Return `null` when no scope is active. The package then only considers global grants. It never widens to "any scope".
- Do not read HTTP request state inside the package; keep that inside your resolver so the same checks work in jobs, commands, and tests.
- Applications without scopes do not need to bind anything. The default resolver returns `null`.
- When you need to evaluate a different scope than the current one, pass it explicitly: `hasPermission($permission, $scope)`, `hasRole($role, $scope)`.

The package does not create the scope model table for you. Your application owns that structure. Configure the pivot column name to match your domain:

```php
'scope' => [
    'model' => App\Models\Workspace::class,
    'foreign_key' => 'workspace_id',
],
```

### Faking the scope in tests

Bind any resolver instance in your test:

```php
app()->instance(ScopeResolver::class, new class implements ScopeResolver {
    public function resolveScopeId(?Model $scope = null): ?int
    {
        return $scope?->getKey() !== null ? (int) $scope->getKey() : 7;
    }
});
```

`OrganizationResolver` and `TenantResolver` remain available as backward-compatible aliases, but `ScopeResolver` is the preferred abstraction going forward.

## Caching

Resolved permission lists are memoised for the duration of the request (or job) in Laravel's hidden [Context](https://laravel.com/docs/context). It is a request-lifetime memo, not a persistent cache.

- Entries are keyed by `<context_cache.key>:<model class>:<model key>` and hold one list per evaluated scope. A user's entry can never be read for another user, and a scope's list can never be read for another scope.
- Only the authenticated model instance is memoised. Checks against other users always hit the database.
- The entry is flushed automatically when the package changes anything that affects permissions: direct permission changes, `assignGroup`/`syncGroups`/`revokeGroup` (scoped and global), role assignment helpers, and `RoleGroupSync`.
- If you change pivot rows outside the package helpers, call `$user->flushPermissionCache()`.
- Disable it with `context_cache.enabled => false`.

Role checks are not cached; they run a single `exists` query.

## Extension points

- `Aapolrac\AccessControl\Contracts\ScopeResolver`: how the current scope is determined.
- `access_control.models.*`: swap the `Role`, `Group`, and `Permission` models for your own Eloquent classes.
- `access_control.tables.*` and `access_control.scope.foreign_key`: adapt table and column names.
- `HasAccessControl` protected methods: `resolveScopeId()`, `constrainToScopeForRead()`, `constrainToScopeForWrite()`, `scopeForeignKey()`, `permissionCacheKey()` can be overridden on your user model.
- `GateRegistrar::registerPermissions()` / `registerRoles()`: register additional Gate abilities from enums or arrays.
- `access_control.groups`: role → default group mapping consumed by `RoleGroupSync`.

### Remaining limitations

- Direct permissions are global. Scoping them would require storing them per scope (a schema change) and is not planned for this release.
- Roles carry no permissions of their own. A role grants permissions only through the default groups configured in `access_control.groups` and maintained by `RoleGroupSync`.

## Filament and other integrations

The core package has no dependency on Filament, Livewire, or any UI layer, and it will stay that way. Everything an integration needs is available through the public API: `hasPermission()`/`hasRole()` with an explicit scope, `ScopeResolver` for the current scope, and Laravel's Gate.

A Filament integration would live in a separate package (for example `carlopaa/access-control-filament`) and would typically:

- bind a `ScopeResolver` that reads Filament's current tenant;
- provide resources/pages for roles, groups, and permissions;
- rely on standard policies, which the Gate hook never bypasses.

Nothing in this repository is Filament-specific.

## Upgrading

### From 0.1.x

Authorization checks are now scope-aware. Review the following:

- `hasPermission()`, `hasAnyPermission()`, `getAllPermissions()`, `hasRole()`, and `hasAnyRole()` evaluate the current scope (from `ScopeResolver`) plus global grants. Previously they considered grants from every scope. If your application assigns roles or groups with a scope but has not bound a `ScopeResolver`, bind one, or pass the scope explicitly.
- `hasRole('x')` was a cross-scope check. Use `hasRoleInAnyScope('x')` for that meaning.
- `withRoleInScope()` without a resolvable scope now matches only global role rows instead of falling back to a cross-scope query.
- `$user->can('ability', $model)` no longer bypasses a policy or a defined ability that can handle the call.
- The Context cache key changed from a single `permissions` entry to `permissions:<model class>:<key>`.
- `assignGroup()`/`assignRole()` for a group or role the user already holds in another scope now adds a row instead of moving the existing one.
- The `role_user` scope column is now nullable so roles can be granted globally. Existing installations must publish and run the new `make_role_user_scope_nullable` migration:

  ```bash
  php artisan vendor:publish --tag="access-control-migrations"
  php artisan migrate
  ```

  The migration only drops the `NOT NULL` constraint on that column. Existing scoped rows, the unique index, and the secondary index are unchanged. Fresh installations get a nullable column from the `create_role_user_table` migration and the extra migration is a no-op.

## Testing

Run package tests:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

## Credits

- [Carlo Garcia Paa](https://github.com/carlopaa)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
