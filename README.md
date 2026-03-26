# Access control for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/carlopaa/access-control.svg?style=flat-square)](https://packagist.org/packages/carlopaa/access-control)
[![Total Downloads](https://img.shields.io/packagist/dt/carlopaa/access-control.svg?style=flat-square)](https://packagist.org/packages/carlopaa/access-control)

`carlopaa/access-control` is a Laravel package for tenant-aware access control using roles, groups, and permissions.

It is intentionally broader than strict RBAC:

- roles can map to default groups
- groups aggregate permissions
- users can also receive direct permissions
- organization-scoped pivots make multi-tenant access checks predictable
- `:deny` permissions explicitly override allows

## Installation

You can install the package via composer:

```bash
composer require carlopaa/access-control
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="access-control-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="access-control-config"
```

## Usage

```php
use Aapolrac\AccessControl\Concerns\HasAccessControl;

class User extends Authenticatable
{
    use HasAccessControl;
}
```

Configure your host app in `config/access_control.php`:

```php
return [
    'models' => [
        'role' => App\Models\Role::class,
        'group' => App\Models\Group::class,
        'permission' => App\Models\Permission::class,
    ],

    'permissions' => [
        'enum_classes' => [
            App\Enums\MemberPermission::class,
            App\Enums\CustomerPermission::class,
        ],
    ],

    'groups' => [
        'owner' => ['manage_members', 'manage_customers'],
    ],
];
```

Sync permissions from your enums:

```bash
php artisan access-control:sync
```

Example checks:

```php
$user->hasRole('owner');
$user->hasPermission('member:view-any');
$user->assignPermission('customer:view-any');

Route::middleware(['auth', 'access.permission:member:view-any'])->group(function () {
    // ...
});
```

## Testing

```bash
composer test
```

## Credits

- [Carlo Garcia Paa](https://github.com/carlopaa)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
