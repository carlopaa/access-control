<?php

declare(strict_types=1);

use Aapolrac\AccessControl\AccessControl;
use Aapolrac\AccessControl\Contracts\TenantResolver;
use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Permission;
use Aapolrac\AccessControl\Models\Role;
use Aapolrac\AccessControl\Support\RoleGroupSync;
use Aapolrac\AccessControl\Tests\Fixtures\CustomGroup;
use Aapolrac\AccessControl\Tests\Fixtures\CustomPermission;
use Aapolrac\AccessControl\Tests\Fixtures\CustomRole;
use Aapolrac\AccessControl\Tests\Fixtures\Enums\MemberPermission;
use Aapolrac\AccessControl\Tests\Fixtures\TenantResolver as TestTenantResolver;
use Aapolrac\AccessControl\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;

it('provides default trait relationships and resolves permissions with deny overrides', function (): void {
    $user = User::create();
    $group = Group::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $allow = Permission::query()->create(['name' => 'member:view-any']);

    $group->permissions()->attach($allow);
    $user->groups()->attach($group->getKey(), ['organization_id' => 1]);

    expect($user->groups()->getRelated()::class)->toBe(Group::class)
        ->and($user->roles()->getRelated()::class)->toBe(Role::class)
        ->and($user->hasPermission('member:view-any'))->toBeTrue();

    $user->assignPermission('member:update');

    expect($user->fresh()->getDirectPermissions()->all())->toBe(['member:update'])
        ->and($user->fresh()->hasPermission('member:update'))->toBeTrue();

    $user->assignPermission('member:view-any:deny');

    expect($user->fresh()->hasPermission('member:view-any'))->toBeFalse()
        ->and($user->fresh()->hasPermission('member:view-any:deny'))->toBeTrue();
});

it('checks roles and organization-aware scopes', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $manager = Role::query()->create(['name' => 'Manager', 'key' => 'manager']);
    $firstUser = User::query()->create();
    $secondUser = User::query()->create();

    $firstUser->roles()->attach($owner->getKey(), ['organization_id' => 10]);
    $secondUser->roles()->attach($manager->getKey(), ['organization_id' => 20]);

    app()->bind(TenantResolver::class, static fn () => new TestTenantResolver(20));

    expect($firstUser->hasRole('owner'))->toBeTrue()
        ->and($firstUser->hasRoleInOrg('owner', 10))->toBeTrue()
        ->and($firstUser->hasRoleInOrg('owner', 20))->toBeFalse()
        ->and(User::query()->withRole('owner')->pluck('id')->all())->toBe([$firstUser->id])
        ->and(User::query()->withRoleInOrg('manager', 20)->pluck('id')->all())->toBe([$secondUser->id])
        ->and(User::query()->withRoleInOrg('manager')->pluck('id')->all())->toBe([$secondUser->id]);
});

it('syncs permissions from configured enums and integrates with gates', function (): void {
    config()->set('access_control.permissions.enum_classes', [MemberPermission::class]);

    $this->artisan('access-control:sync')
        ->expectsOutputToContain('Synced 2 permission(s)')
        ->assertSuccessful();

    $user = User::create();
    $group = Group::query()->create(['name' => 'Members', 'key' => 'members']);
    $permission = Permission::query()->where('name', MemberPermission::ViewAny->value)->firstOrFail();

    $group->permissions()->attach($permission);
    $user->groups()->attach($group->getKey(), ['organization_id' => 1]);

    expect($user->can(MemberPermission::ViewAny->value))->toBeTrue()
        ->and($user->can(MemberPermission::Update->value))->toBeFalse()
        ->and(Permission::query()->pluck('name')->all())
            ->toEqualCanonicalizing([MemberPermission::ViewAny->value, MemberPermission::Update->value]);
});

it('registers middleware aliases that enforce package permissions and roles', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $group = Group::query()->create(['name' => 'Members', 'key' => 'members']);
    $permission = Permission::query()->create(['name' => 'member:view-any']);
    $user = User::create();

    $group->permissions()->attach($permission);
    $user->groups()->attach($group->getKey(), ['organization_id' => 1]);
    $user->roles()->attach($owner->getKey(), ['organization_id' => 1]);

    Route::middleware('access.permission:member:view-any')->get('/permission-allowed', fn () => 'ok');
    Route::middleware('access.permission:member:update')->get('/permission-denied', fn () => 'ok');
    Route::middleware('access.role:owner')->get('/role-allowed', fn () => 'ok');
    Route::middleware('access.role:manager')->get('/role-denied', fn () => 'ok');

    $this->actingAs($user)
        ->get('/permission-allowed')
        ->assertOk();

    $this->actingAs($user)
        ->get('/permission-denied')
        ->assertForbidden();

    $this->actingAs($user)
        ->get('/role-allowed')
        ->assertOk();

    $this->actingAs($user)
        ->get('/role-denied')
        ->assertForbidden();
});

it('syncs configured default groups for a users roles', function (): void {
    config()->set('access_control.groups', [
        'owner' => ['owners', 'team-management'],
        'manager' => ['team-management'],
    ]);

    $owners = Group::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $teamManagement = Group::query()->create(['name' => 'Team Management', 'key' => 'team-management']);
    $legacy = Group::query()->create(['name' => 'Legacy', 'key' => 'legacy']);
    $user = User::create();

    $user->groups()->attach($legacy->getKey(), ['organization_id' => 30]);

    RoleGroupSync::syncDefaultsForRoles($user, 30, ['owner']);

    expect($user->groups()->wherePivot('organization_id', 30)->pluck('key')->all())
        ->toEqualCanonicalizing([$legacy->key, $owners->key, $teamManagement->key]);

    RoleGroupSync::syncDefaultsForRoles($user, 30, ['manager']);

    expect($user->fresh()->groups()->wherePivot('organization_id', 30)->pluck('key')->all())
        ->toEqualCanonicalizing([$legacy->key, $teamManagement->key]);
});

it('binds the package entry point into the container', function (): void {
    expect(app(AccessControl::class))->toBeInstanceOf(AccessControl::class);
});

it('honors custom model and table overrides across relationships and sync helpers', function (): void {
    config()->set('access_control.models.role', CustomRole::class);
    config()->set('access_control.models.group', CustomGroup::class);
    config()->set('access_control.models.permission', CustomPermission::class);
    config()->set('access_control.tables', [
        'roles' => 'acl_roles',
        'groups' => 'acl_groups',
        'permissions' => 'acl_permissions',
        'role_user' => 'acl_role_user',
        'group_user' => 'acl_group_user',
        'group_permission' => 'acl_group_permission',
    ]);
    config()->set('access_control.groups', [
        'owner' => ['owners'],
    ]);

    createCustomAccessControlTables();

    $role = CustomRole::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $group = CustomGroup::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $permission = CustomPermission::query()->create(['name' => 'reports:view']);
    $user = User::create();

    $group->permissions()->attach($permission);
    $user->roles()->attach($role->getKey(), ['organization_id' => 77]);
    $user->groups()->attach($group->getKey(), ['organization_id' => 77]);

    expect($user->roles()->getRelated()::class)->toBe(CustomRole::class)
        ->and($user->groups()->getRelated()::class)->toBe(CustomGroup::class)
        ->and((new CustomRole)->getTable())->toBe('acl_roles')
        ->and((new CustomGroup)->getTable())->toBe('acl_groups')
        ->and((new CustomPermission)->getTable())->toBe('acl_permissions')
        ->and($user->hasRoleInOrg('owner', 77))->toBeTrue()
        ->and($user->hasPermission('reports:view'))->toBeTrue()
        ->and(User::query()->withRoleInOrg('owner', 77)->pluck('id')->all())->toBe([$user->id]);

    RoleGroupSync::syncDefaultsForRoles($user, 77, ['owner']);

    expect($user->fresh()->groups()->wherePivot('organization_id', 77)->pluck('key')->all())
        ->toEqualCanonicalizing([$group->key]);
});

function createCustomAccessControlTables(): void
{
    Schema::create('acl_roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('key')->unique();
        $table->timestamps();
    });

    Schema::create('acl_groups', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('key')->unique();
        $table->timestamps();
    });

    Schema::create('acl_permissions', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->unique();
        $table->string('description')->nullable();
        $table->timestamps();
    });

    Schema::create('acl_role_user', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('organization_id');
        $table->timestamps();
    });

    Schema::create('acl_group_user', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('group_id');
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('organization_id')->nullable();
        $table->timestamps();
    });

    Schema::create('acl_group_permission', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('group_id');
        $table->unsignedBigInteger('permission_id');
        $table->timestamps();
    });
}
