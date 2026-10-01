<?php

declare(strict_types=1);

use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Permission;
use Aapolrac\AccessControl\Models\Role;
use Aapolrac\AccessControl\Tests\Fixtures\SwitchableScopeResolver;
use Aapolrac\AccessControl\Tests\Fixtures\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('access.permission:reports:view')->get('/reports', fn () => 'ok');
    Route::middleware('access.permission:reports:view,invoices:view')->get('/finance', fn () => 'ok');
    Route::middleware('access.role:owner')->get('/owner', fn () => 'ok');
    Route::middleware('access.role:owner,manager')->get('/management', fn () => 'ok');
});

function middlewareUser(): User
{
    $user = User::create();

    $analysts = Group::query()->create(['name' => 'Analysts', 'key' => 'analysts']);
    $analysts->permissions()->attach(Permission::query()->create(['name' => 'reports:view']));
    $user->groups()->attach($analysts->getKey(), ['organization_id' => 1]);

    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $user->roles()->attach($owner->getKey(), ['organization_id' => 1]);

    return $user;
}

it('rejects guests with 401', function (): void {
    $this->get('/reports')->assertUnauthorized();
    $this->get('/owner')->assertUnauthorized();
});

it('enforces permissions and roles in the active scope', function (): void {
    $user = middlewareUser();
    app()->instance(ScopeResolver::class, new SwitchableScopeResolver(1));

    $this->actingAs($user)->get('/reports')->assertOk();
    $this->actingAs($user)->get('/finance')->assertOk();
    $this->actingAs($user)->get('/owner')->assertOk();
    $this->actingAs($user)->get('/management')->assertOk();
});

it('denies permissions and roles granted in another scope', function (): void {
    $user = middlewareUser();
    app()->instance(ScopeResolver::class, new SwitchableScopeResolver(2));

    $this->actingAs($user)->get('/reports')->assertForbidden();
    $this->actingAs($user)->get('/finance')->assertForbidden();
    $this->actingAs($user)->get('/owner')->assertForbidden();
    $this->actingAs($user)->get('/management')->assertForbidden();
});

it('denies scoped grants when no scope is active', function (): void {
    $user = middlewareUser();

    $this->actingAs($user)->get('/reports')->assertForbidden();
    $this->actingAs($user)->get('/owner')->assertForbidden();
});

it('allows global grants with or without an active scope', function (): void {
    $user = User::create();
    $user->assignPermission('reports:view');

    $this->actingAs($user)->get('/reports')->assertOk();

    app()->instance(ScopeResolver::class, new SwitchableScopeResolver(42));

    $this->actingAs($user)->get('/reports')->assertOk();
});

it('agrees with direct authorization calls in every scope state', function (): void {
    $user = middlewareUser();
    $resolver = new SwitchableScopeResolver(null);
    app()->instance(ScopeResolver::class, $resolver);

    foreach ([null, 1, 2] as $scopeId) {
        $resolver->scopeId = $scopeId;

        $permissionStatus = $this->actingAs($user)->get('/reports')->status();
        $roleStatus = $this->actingAs($user)->get('/owner')->status();

        expect($permissionStatus === 200)->toBe($user->hasAnyPermission(['reports:view']))
            ->and($roleStatus === 200)->toBe($user->hasAnyRole(['owner']));
    }
});
