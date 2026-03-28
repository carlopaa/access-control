# Access control for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/carlopaa/access-control.svg?style=flat-square)](https://packagist.org/packages/carlopaa/access-control)
[![Total Downloads](https://img.shields.io/packagist/dt/carlopaa/access-control.svg?style=flat-square)](https://packagist.org/packages/carlopaa/access-control)

`carlopaa/access-control` is a scope-aware access control package for Laravel.

It combines role-based and permission-based patterns:

- users get roles in a configurable scope context
- roles can map to default groups
- groups aggregate permissions
- users can also receive direct permissions
- `:deny` permissions override allows

## Table of contents

- [Installation](#installation)
- [Quick start](#quick-start)
- [Required model setup](#required-model-setup)
- [Configuration reference](#configuration-reference)
- [Commands](#commands)
- [Using permissions and roles](#using-permissions-and-roles)
- [Role to default group sync](#role-to-default-group-sync)
- [Middleware](#middleware)
- [Gate integration](#gate-integration)
- [Scope resolution](#scope-resolution)
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

3. Configure `config/access_control.php` (models, enum classes, groups).

4. Seed permissions from your enums:

```bash
php artisan access-control:sync
```

5. Use checks in code:

```php
$user->assignRole('owner', $scopeId);
$user->hasPermission('member:view-any');
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

Your `Group` model should have permissions relation:

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
        'model' => App\Models\Team::class,
        'foreign_key' => 'team_id',
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

## Using permissions and roles

### Permission checks

```php
$user->hasPermission('member:view-any');
$user->hasAnyPermission(['member:view-any', 'member:update']);
```

### Deny override

If a user has `member:view-any:deny`, then `hasPermission('member:view-any')` returns `false` even if the allow exists from groups or direct permissions.

### Direct permission API

```php
$user->assignPermission('customer:view-any');
$user->assignPermissions(['member:view-any', 'member:update']);
$user->revokePermission('member:update');
$user->syncDirectPermissions(['member:view-any']);
$user->clearDirectPermissions();
$direct = $user->getDirectPermissions(); // Collection
```

### Role checks

```php
$user->hasRole('owner');
$user->hasAnyRole(['owner', 'manager']);
$user->hasRoleInScope('owner', $scopeId);
$user->hasAnyRoleInScope(['owner', 'manager'], $scopeId);
$user->hasRoleInOrg('owner', $organizationId);
$user->hasAnyRoleInOrg(['owner', 'manager'], $organizationId);
```

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

These helpers accept ids, keys, or a scope model instance and always scope the change to a specific scope record.

### Query scopes

```php
User::query()->withRole('owner')->get();
User::query()->withAnyRoles(['owner', 'manager'])->get();
User::query()->withRoleInScope('owner', $scopeId)->get();
User::query()->withAnyRolesInScope(['owner', 'manager'], $scopeId)->get();
User::query()->withRoleInOrg('owner', $organizationId)->get();
User::query()->withAnyRolesInOrg(['owner', 'manager'], $organizationId)->get();
```

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

## Gate integration

The package registers a `Gate::before` hook. If the user model has `hasPermission()`, then:

```php
$user->can('member:view-any');
Gate::allows('member:view-any', $user);
```

Both resolve through package permissions.

Additionally, enum-based permission abilities can be auto-registered from `permissions.enum_classes`.

## Scope resolution

If your checks/scopes need an implicit active scope, bind your own resolver:

```php
use Aapolrac\AccessControl\Contracts\ScopeResolver;

app()->bind(ScopeResolver::class, YourScopeResolver::class);
```

The package does not create the scope model table for you. Your application owns that structure.
Configure the scope model and foreign key to match your domain:

```php
'scope' => [
    'model' => App\Models\Team::class,
    'foreign_key' => 'team_id',
],
```

Your resolver must return the current scope id (or `null`):

```php
public function resolveScopeId(?Model $scope = null): ?int
{
    return $scope?->getKey() ? (int) $scope->getKey() : null;
}
```

`OrganizationResolver` and `TenantResolver` remain available as backward-compatible aliases, but `ScopeResolver` is the preferred abstraction going forward.

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
