# Access control for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/carlopaa/access-control.svg?style=flat-square)](https://packagist.org/packages/carlopaa/access-control)
[![Total Downloads](https://img.shields.io/packagist/dt/carlopaa/access-control.svg?style=flat-square)](https://packagist.org/packages/carlopaa/access-control)

`carlopaa/access-control` is a tenant-aware access control package for Laravel.

It combines role-based and permission-based patterns:

- users get roles in an organization context
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
- [Tenant resolution](#tenant-resolution)
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

3. Configure `config/access_control.php` (models, enum classes, groups).

4. Seed permissions from your enums:

```bash
php artisan access-control:sync
```

5. Use checks in code:

```php
$user->hasRole('owner');
$user->hasPermission('member:view-any');
```

## Required model setup

The trait expects `roles()` and `groups()` relations on the user model.

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

public function roles(): BelongsToMany
{
    return $this->belongsToMany(
        Role::class,
        config('access_control.tables.role_user', 'role_user')
    )->withPivot('organization_id')->withTimestamps();
}

public function groups(): BelongsToMany
{
    return $this->belongsToMany(
        Group::class,
        config('access_control.tables.group_user', 'group_user')
    )->withPivot('organization_id')->withTimestamps();
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
        'role' => App\Models\Role::class,
        'group' => App\Models\Group::class,
        'permission' => App\Models\Permission::class,
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

## Commands

Check package installation:

```bash
php artisan access-control
```

Sync permission records from configured enum classes:

```bash
php artisan access-control:sync
php artisan access-control:sync --only-missing
```

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
$user->hasRoleInOrg('owner', $organizationId);
$user->hasAnyRoleInOrg(['owner', 'manager'], $organizationId);
```

### Query scopes

```php
User::query()->withRole('owner')->get();
User::query()->withAnyRoles(['owner', 'manager'])->get();
User::query()->withRoleInOrg('owner', $organizationId)->get();
User::query()->withAnyRolesInOrg(['owner', 'manager'], $organizationId)->get();
```

## Role to default group sync

Use `RoleGroupSync` when role assignment should automatically maintain configured default groups:

```php
use Aapolrac\AccessControl\Support\RoleGroupSync;

RoleGroupSync::syncDefaultsForRoles($user, $organizationId, ['owner', 'manager']);
```

You can also attach explicit groups by key or id:

```php
RoleGroupSync::attach($user, $organizationId, ['members.manage', 5]);
RoleGroupSync::attach($user, $organizationId, ['team-management', 5]);
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

## Tenant resolution

If your checks/scopes need an implicit active organization, bind your own resolver:

```php
use Aapolrac\AccessControl\Contracts\TenantResolver;

app()->bind(TenantResolver::class, YourTenantResolver::class);
```

Your resolver must return the current organization id (or `null`):

```php
public function resolveOrganizationId(?Model $tenant = null): ?int
{
    return $tenant?->getKey() ? (int) $tenant->getKey() : null;
}
```

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
