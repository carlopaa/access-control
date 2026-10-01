<?php

declare(strict_types=1);

use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Permission;
use Aapolrac\AccessControl\Models\Role;
use Aapolrac\AccessControl\Tests\Fixtures\SwitchableScopeResolver;
use Aapolrac\AccessControl\Tests\Fixtures\User;

/**
 * Create a group carrying one permission and attach it to the user in the given
 * scope (null = global membership).
 */
function scopedAuthGrant(User $user, string $groupKey, string $permission, ?int $scopeId): Group
{
    $group = Group::query()->firstOrCreate(['key' => $groupKey], ['name' => ucfirst($groupKey)]);
    $permissionModel = Permission::query()->firstOrCreate(['name' => $permission]);

    $group->permissions()->syncWithoutDetaching([$permissionModel->getKey()]);
    $user->groups()->attach($group->getKey(), ['organization_id' => $scopeId]);

    return $group;
}

function scopedAuthUseScope(?int $scopeId): SwitchableScopeResolver
{
    $resolver = new SwitchableScopeResolver($scopeId);

    app()->instance(ScopeResolver::class, $resolver);

    return $resolver;
}

/*
|--------------------------------------------------------------------------
| A. Non-scoped application
|--------------------------------------------------------------------------
*/

it('authorizes direct permissions without any scope or resolver', function (): void {
    $user = User::create();
    $user->assignPermission('reports:view');

    expect($user->hasPermission('reports:view'))->toBeTrue()
        ->and($user->hasPermission('invoices:view'))->toBeFalse()
        ->and($user->hasAnyPermission(['invoices:view', 'reports:view']))->toBeTrue();
});

it('authorizes global group permissions without any scope or resolver', function (): void {
    $user = User::create();
    scopedAuthGrant($user, 'staff', 'reports:view', null);

    expect($user->hasPermission('reports:view'))->toBeTrue()
        ->and($user->getAllPermissions()->all())->toBe(['reports:view']);
});

it('lets a non-scoped application assign global groups through the trait', function (): void {
    $user = User::create();
    $group = Group::query()->create(['name' => 'Staff', 'key' => 'staff']);
    $permission = Permission::query()->create(['name' => 'reports:view']);
    $group->permissions()->attach($permission);

    $user->assignGlobalGroup('staff');

    expect($user->hasPermission('reports:view'))->toBeTrue()
        ->and($user->groups()->wherePivotNull('organization_id')->pluck('key')->all())->toBe(['staff']);
});

/*
|--------------------------------------------------------------------------
| B. Scoped isolation (permissions)
|--------------------------------------------------------------------------
*/

it('isolates group permissions between scopes', function (): void {
    $user = User::create();
    scopedAuthGrant($user, 'group-a', 'reports:view', 1);
    scopedAuthGrant($user, 'group-b', 'invoices:view', 2);

    $resolver = scopedAuthUseScope(1);

    expect($user->hasPermission('reports:view'))->toBeTrue()
        ->and($user->hasPermission('invoices:view'))->toBeFalse()
        ->and($user->getAllPermissions()->all())->toBe(['reports:view']);

    $resolver->scopeId = 2;

    expect($user->hasPermission('reports:view'))->toBeFalse()
        ->and($user->hasPermission('invoices:view'))->toBeTrue()
        ->and($user->getAllPermissions()->all())->toBe(['invoices:view']);
});

it('honours an explicit scope argument over the resolved scope', function (): void {
    $user = User::create();
    scopedAuthGrant($user, 'group-a', 'reports:view', 1);
    scopedAuthGrant($user, 'group-b', 'invoices:view', 2);

    scopedAuthUseScope(1);

    expect($user->hasPermission('invoices:view', 2))->toBeTrue()
        ->and($user->hasPermission('reports:view', 2))->toBeFalse()
        ->and($user->hasAnyPermission(['reports:view'], 2))->toBeFalse()
        ->and($user->hasAnyPermission(['reports:view', 'invoices:view'], 2))->toBeTrue()
        ->and($user->getAllPermissions(2)->all())->toBe(['invoices:view']);
});

it('accepts a scope model instance as the explicit scope', function (): void {
    $user = User::create();
    $scope = Role::query()->create(['name' => 'Stand-in scope model', 'key' => 'scope-model']); // any model with a key

    scopedAuthGrant($user, 'group-a', 'reports:view', (int) $scope->getKey());

    expect($user->hasPermission('reports:view', $scope))->toBeTrue()
        ->and($user->hasPermission('reports:view', (int) $scope->getKey() + 1))->toBeFalse();
});

it('does not let a scope inherit permissions from another scope through the same group', function (): void {
    $user = User::create();
    $group = scopedAuthGrant($user, 'editors', 'posts:edit', 1);

    scopedAuthUseScope(2);

    expect($user->hasPermission('posts:edit'))->toBeFalse();

    $user->groups()->attach($group->getKey(), ['organization_id' => 2]);

    expect($user->hasPermission('posts:edit'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| C. Role isolation
|--------------------------------------------------------------------------
*/

it('isolates roles between scopes', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $viewer = Role::query()->create(['name' => 'Viewer', 'key' => 'viewer']);
    $user = User::create();

    $user->roles()->attach($owner->getKey(), ['organization_id' => 1]);
    $user->roles()->attach($viewer->getKey(), ['organization_id' => 2]);

    $resolver = scopedAuthUseScope(1);

    expect($user->hasRole('owner'))->toBeTrue()
        ->and($user->hasRole('viewer'))->toBeFalse()
        ->and($user->hasAnyRole(['viewer']))->toBeFalse()
        ->and($user->hasAnyRole(['viewer', 'owner']))->toBeTrue();

    $resolver->scopeId = 2;

    expect($user->hasRole('owner'))->toBeFalse()
        ->and($user->hasRole('viewer'))->toBeTrue()
        ->and($user->hasRole('owner', 1))->toBeTrue()
        ->and($user->hasAnyRole(['owner'], 1))->toBeTrue()
        ->and($user->hasRoleInScope('owner', 1))->toBeTrue()
        ->and($user->hasRoleInScope('owner', 2))->toBeFalse();
});

it('offers an explicit cross-scope role query that is distinct from the scoped check', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $user = User::create();
    $user->roles()->attach($owner->getKey(), ['organization_id' => 1]);

    scopedAuthUseScope(2);

    expect($user->hasRole('owner'))->toBeFalse()
        ->and($user->hasRoleInAnyScope('owner'))->toBeTrue()
        ->and($user->hasAnyRoleInAnyScope(['owner', 'viewer']))->toBeTrue()
        ->and($user->hasAnyRoleInAnyScope(['viewer']))->toBeFalse()
        ->and(User::query()->withRole('owner')->pluck('id')->all())->toBe([$user->id])
        ->and(User::query()->withRoleInScope('owner')->pluck('id')->all())->toBe([])
        ->and(User::query()->withRoleInScope('owner', 1)->pluck('id')->all())->toBe([$user->id])
        ->and(User::query()->withAnyRolesInScope(['owner'], 2)->pluck('id')->all())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| D. Global authorization is explicit
|--------------------------------------------------------------------------
*/

it('applies global group permissions in every scope and with no scope', function (): void {
    $user = User::create();
    scopedAuthGrant($user, 'staff', 'reports:view', null);

    $resolver = scopedAuthUseScope(null);
    expect($user->hasPermission('reports:view'))->toBeTrue();

    $resolver->scopeId = 1;
    expect($user->hasPermission('reports:view'))->toBeTrue();

    $resolver->scopeId = 2;
    expect($user->hasPermission('reports:view'))->toBeTrue()
        ->and($user->hasPermission('reports:view', 99))->toBeTrue();
});

it('applies direct permissions in every scope and with no scope', function (): void {
    $user = User::create();
    $user->assignPermission('reports:view');

    $resolver = scopedAuthUseScope(null);
    expect($user->hasPermission('reports:view'))->toBeTrue();

    $resolver->scopeId = 7;
    expect($user->hasPermission('reports:view'))->toBeTrue();
});

it('refuses an implicit global grant when a scoped helper receives no scope', function (): void {
    Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    Group::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $user = User::create();

    scopedAuthUseScope(null);

    expect(fn () => $user->assignRole('owner', null))
        ->toThrow(InvalidArgumentException::class, 'A scope is required')
        ->and(fn () => $user->assignGroup('owners', null))
        ->toThrow(InvalidArgumentException::class, 'A scope is required')
        ->and(fn () => $user->syncGroups(['owners'], null))
        ->toThrow(InvalidArgumentException::class)
        ->and($user->groups()->count())->toBe(0)
        ->and($user->roles()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| E. No-scope context
|--------------------------------------------------------------------------
*/

it('does not grant scoped permissions when no scope is active', function (): void {
    $user = User::create();
    scopedAuthGrant($user, 'group-a', 'reports:view', 1);

    // Default resolver (nothing bound) resolves to no scope.
    expect($user->hasPermission('reports:view'))->toBeFalse()
        ->and($user->getAllPermissions()->all())->toBe([]);

    // A bound resolver that returns null is treated the same way.
    scopedAuthUseScope(null);

    expect($user->hasPermission('reports:view'))->toBeFalse();
});

it('does not grant scoped roles when no scope is active', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $user = User::create();
    $user->roles()->attach($owner->getKey(), ['organization_id' => 1]);

    expect($user->hasRole('owner'))->toBeFalse()
        ->and($user->hasAnyRole(['owner']))->toBeFalse()
        ->and($user->hasRoleInAnyScope('owner'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| L. Deny semantics under scopes
|--------------------------------------------------------------------------
*/

it('applies a scoped deny only in its own scope and a global deny everywhere', function (): void {
    $user = User::create();
    scopedAuthGrant($user, 'staff', 'reports:view', null);
    scopedAuthGrant($user, 'restricted', 'reports:view:deny', 2);

    $resolver = scopedAuthUseScope(1);
    expect($user->hasPermission('reports:view'))->toBeTrue();

    $resolver->scopeId = 2;
    expect($user->hasPermission('reports:view'))->toBeFalse()
        ->and($user->hasPermission('reports:view:deny'))->toBeTrue();

    $user->assignPermission('reports:view:deny'); // direct permissions are global

    $resolver->scopeId = 1;
    expect($user->hasPermission('reports:view'))->toBeFalse();
});
