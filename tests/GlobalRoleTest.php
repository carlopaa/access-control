<?php

declare(strict_types=1);

use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Permission;
use Aapolrac\AccessControl\Models\Role;
use Aapolrac\AccessControl\Support\RoleGroupSync;
use Aapolrac\AccessControl\Tests\Fixtures\SwitchableScopeResolver;
use Aapolrac\AccessControl\Tests\Fixtures\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function globalRoleUseScope(?int $scopeId): SwitchableScopeResolver
{
    $resolver = new SwitchableScopeResolver($scopeId);

    app()->instance(ScopeResolver::class, $resolver);

    return $resolver;
}

function globalRoleGroupWithPermission(string $groupKey, string $permission): Group
{
    $group = Group::query()->firstOrCreate(['key' => $groupKey], ['name' => ucfirst($groupKey)]);
    $group->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $permission])->getKey()]);

    return $group;
}

/** @return array<int, array{role_id: int, organization_id: int|null}> */
function globalRoleRows(User $user): array
{
    return DB::table('role_user')
        ->where('user_id', $user->getKey())
        ->orderBy('id')
        ->get()
        ->map(static fn ($row) => ['role_id' => (int) $row->role_id, 'organization_id' => $row->organization_id === null ? null : (int) $row->organization_id])
        ->all();
}

/*
|--------------------------------------------------------------------------
| Schema / migration
|--------------------------------------------------------------------------
*/

it('ships a role pivot whose scope column is nullable, with indexes intact', function (): void {
    $column = collect(Schema::getColumns('role_user'))->firstWhere('name', 'organization_id');
    $indexes = collect(Schema::getIndexes('role_user'));

    expect($column['nullable'])->toBeTrue()
        ->and($indexes->firstWhere('name', 'access_control_role_user_scope_unique'))->toMatchArray(['unique' => true])
        ->and($indexes->firstWhere('name', 'access_control_role_user_scope_unique')['columns'])->toBe(['user_id', 'role_id', 'organization_id'])
        ->and($indexes->firstWhere('name', 'access_control_role_user_scope_role_index')['columns'])->toBe(['organization_id', 'role_id']);
});

it('upgrades a legacy NOT NULL role pivot without losing scoped rows', function (): void {
    // Rebuild the pivot exactly as the v0.1.3 migration created it.
    Schema::drop('role_user');
    Schema::create('role_user', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('organization_id');
        $table->timestamps();
        $table->unique(['user_id', 'role_id', 'organization_id'], 'access_control_role_user_scope_unique');
        $table->index(['organization_id', 'role_id'], 'access_control_role_user_scope_role_index');
    });

    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $user = User::create();
    $user->roles()->attach($owner->getKey(), ['organization_id' => 7]);

    expect(fn () => $user->roles()->attach($owner->getKey(), ['organization_id' => null]))->toThrow(QueryException::class);

    $migration = include __DIR__.'/../database/migrations/make_role_user_scope_nullable.php.stub';
    $migration->up();

    $column = collect(Schema::getColumns('role_user'))->firstWhere('name', 'organization_id');

    expect($column['nullable'])->toBeTrue()
        ->and($column['type_name'])->toBe('integer')
        ->and(collect(Schema::getIndexes('role_user'))->pluck('name')->all())
        ->toContain('access_control_role_user_scope_unique', 'access_control_role_user_scope_role_index')
        ->and(globalRoleRows($user))->toBe([['role_id' => $owner->id, 'organization_id' => 7]])
        ->and($user->hasRole('owner', 7))->toBeTrue();

    $user->assignGlobalRole('owner');

    expect(globalRoleRows($user))->toBe([
        ['role_id' => $owner->id, 'organization_id' => 7],
        ['role_id' => $owner->id, 'organization_id' => null],
    ]);

    // Running the migration again is a no-op, and down() restores NOT NULL once global rows are gone.
    $migration->up();
    $user->revokeGlobalRole('owner');
    $migration->down();

    expect(collect(Schema::getColumns('role_user'))->firstWhere('name', 'organization_id')['nullable'])->toBeFalse()
        ->and(globalRoleRows($user))->toBe([['role_id' => $owner->id, 'organization_id' => 7]]);
});

/*
|--------------------------------------------------------------------------
| Global role API
|--------------------------------------------------------------------------
*/

it('assigns, syncs, and revokes global roles without touching scoped rows', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $auditor = Role::query()->create(['name' => 'Auditor', 'key' => 'auditor']);
    $user = User::create();

    $user->assignRole('owner', 1);
    $user->assignGlobalRoles(['owner', $auditor->id]);
    $user->assignGlobalRole('owner'); // idempotent

    expect(globalRoleRows($user))->toEqualCanonicalizing([
        ['role_id' => $owner->id, 'organization_id' => 1],
        ['role_id' => $owner->id, 'organization_id' => null],
        ['role_id' => $auditor->id, 'organization_id' => null],
    ]);

    $user->syncGlobalRoles(['auditor']);

    expect(globalRoleRows($user))->toEqualCanonicalizing([
        ['role_id' => $owner->id, 'organization_id' => 1],
        ['role_id' => $auditor->id, 'organization_id' => null],
    ]);

    $user->revokeGlobalRoles(['auditor']);
    $user->revokeGlobalRole('owner'); // not global: no-op

    expect(globalRoleRows($user))->toBe([
        ['role_id' => $owner->id, 'organization_id' => 1],
    ]);

    // Scoped sync and revoke never touch the global row either.
    $user->assignGlobalRole('auditor');
    $user->syncRoles([], 1);
    $user->revokeRole('auditor', 1);

    expect(globalRoleRows($user))->toBe([
        ['role_id' => $auditor->id, 'organization_id' => null],
    ]);
});

it('still refuses a null scope on the scoped role helpers', function (): void {
    Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $user = User::create();

    expect(fn () => $user->assignRole('owner', null))
        ->toThrow(InvalidArgumentException::class, 'A scope is required')
        ->and(fn () => $user->syncRoles(['owner'], null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $user->revokeRole('owner', null))
        ->toThrow(InvalidArgumentException::class)
        ->and(globalRoleRows($user))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Semantics matrix
|--------------------------------------------------------------------------
|
|                          No Scope   Scope A   Scope B
| Global Permission          YES        YES       YES
| Global Group               YES        YES       YES
| Global Role                YES        YES       YES
| Group @ A                  NO         YES       NO
| Role @ A                   NO         YES       NO
*/

it('global roles are visible with no scope and in every scope', function (): void {
    Role::query()->create(['name' => 'Auditor', 'key' => 'auditor']);
    $user = User::create();
    $user->assignGlobalRole('auditor');

    $resolver = globalRoleUseScope(null);
    expect($user->hasRole('auditor'))->toBeTrue()->and($user->hasAnyRole(['auditor', 'owner']))->toBeTrue();

    $resolver->scopeId = 1;
    expect($user->hasRole('auditor'))->toBeTrue();

    $resolver->scopeId = 2;
    expect($user->hasRole('auditor'))->toBeTrue()
        ->and($user->hasRole('auditor', 99))->toBeTrue()
        ->and($user->hasRoleInScope('auditor', 99))->toBeTrue()
        ->and($user->hasRoleInAnyScope('auditor'))->toBeTrue()
        ->and(User::query()->withRoleInScope('auditor')->pluck('id')->all())->toBe([$user->id])
        ->and(User::query()->withRoleInScope('auditor', 5)->pluck('id')->all())->toBe([$user->id]);
});

it('scoped roles are visible only in their own scope', function (): void {
    Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $user = User::create();
    $user->assignRole('owner', 1);

    $resolver = globalRoleUseScope(1);
    expect($user->hasRole('owner'))->toBeTrue();

    $resolver->scopeId = 2;
    expect($user->hasRole('owner'))->toBeFalse();

    $resolver->scopeId = null;
    expect($user->hasRole('owner'))->toBeFalse()
        ->and(User::query()->withRoleInScope('owner')->pluck('id')->all())->toBe([]);
});

it('a global role grants its default groups\' permissions everywhere through RoleGroupSync', function (): void {
    config()->set('access_control.groups', ['auditor' => ['auditors']]);

    Role::query()->create(['name' => 'Auditor', 'key' => 'auditor']);
    globalRoleGroupWithPermission('auditors', 'reports:view');
    $user = User::create();

    $user->assignGlobalRole('auditor');
    RoleGroupSync::syncGlobalDefaultsForRoles($user, ['auditor']);

    $resolver = globalRoleUseScope(null);
    expect($user->hasPermission('reports:view'))->toBeTrue();

    $resolver->scopeId = 1;
    expect($user->hasPermission('reports:view'))->toBeTrue();

    $resolver->scopeId = 2;
    expect($user->hasPermission('reports:view'))->toBeTrue();

    expect(DB::table('group_user')->where('user_id', $user->getKey())->whereNull('organization_id')->count())->toBe(1);
});

it('a scoped role grants its default groups\' permissions only in that scope through RoleGroupSync', function (): void {
    config()->set('access_control.groups', ['owner' => ['owners']]);

    Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    globalRoleGroupWithPermission('owners', 'billing:manage');
    $user = User::create();

    $user->assignRole('owner', 1);
    RoleGroupSync::syncDefaultsForRoles($user, 1, ['owner']);

    $resolver = globalRoleUseScope(1);
    expect($user->hasPermission('billing:manage'))->toBeTrue();

    $resolver->scopeId = 2;
    expect($user->hasPermission('billing:manage'))->toBeFalse();

    $resolver->scopeId = null;
    expect($user->hasPermission('billing:manage'))->toBeFalse();
});

it('global and scoped default-group sync manage disjoint pivot rows', function (): void {
    config()->set('access_control.groups', ['owner' => ['owners'], 'auditor' => ['auditors']]);

    $owners = Group::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $auditors = Group::query()->create(['name' => 'Auditors', 'key' => 'auditors']);
    $user = User::create();

    RoleGroupSync::syncDefaultsForRoles($user, 1, ['owner']);
    RoleGroupSync::syncGlobalDefaultsForRoles($user, ['auditor']);
    RoleGroupSync::attachGlobal($user, ['auditors']); // idempotent

    $rows = fn () => DB::table('group_user')->where('user_id', $user->getKey())->get()
        ->map(static fn ($row) => [(int) $row->group_id, $row->organization_id === null ? null : (int) $row->organization_id])->all();

    expect($rows())->toEqualCanonicalizing([[$owners->id, 1], [$auditors->id, null]]);

    // Removing the global role's defaults leaves the scoped row; and vice versa.
    RoleGroupSync::syncGlobalDefaultsForRoles($user, []);
    expect($rows())->toBe([[$owners->id, 1]]);

    RoleGroupSync::syncGlobalDefaultsForRoles($user, ['owner']);
    RoleGroupSync::syncDefaultsForRoles($user, 1, []);
    expect($rows())->toBe([[$owners->id, null]]);
});

it('verifies the full semantics matrix for every grant kind', function (): void {
    config()->set('access_control.groups', []);

    $user = User::create();
    $resolver = globalRoleUseScope(null);

    // Global permission
    $user->assignPermission('p:direct');
    // Global group
    globalRoleGroupWithPermission('g-global', 'p:global-group');
    $user->assignGlobalGroup('g-global');
    // Global role
    Role::query()->create(['name' => 'R Global', 'key' => 'r-global']);
    $user->assignGlobalRole('r-global');
    // Group @ A
    globalRoleGroupWithPermission('g-a', 'p:group-a');
    $user->assignGroup('g-a', 1);
    // Role @ A
    Role::query()->create(['name' => 'R A', 'key' => 'r-a']);
    $user->assignRole('r-a', 1);

    $matrix = [];

    foreach (['none' => null, 'A' => 1, 'B' => 2] as $label => $scopeId) {
        $resolver->scopeId = $scopeId;

        $matrix[$label] = [
            'global permission' => $user->hasPermission('p:direct'),
            'global group' => $user->hasPermission('p:global-group'),
            'global role' => $user->hasRole('r-global'),
            'group @ A' => $user->hasPermission('p:group-a'),
            'role @ A' => $user->hasRole('r-a'),
        ];
    }

    expect($matrix)->toBe([
        'none' => ['global permission' => true, 'global group' => true, 'global role' => true, 'group @ A' => false, 'role @ A' => false],
        'A' => ['global permission' => true, 'global group' => true, 'global role' => true, 'group @ A' => true, 'role @ A' => true],
        'B' => ['global permission' => true, 'global group' => true, 'global role' => true, 'group @ A' => false, 'role @ A' => false],
    ]);
});

it('lets a non-scoped application use roles, groups, and permissions without a scope resolver', function (): void {
    config()->set('access_control.groups', ['admin' => ['admins']]);

    Role::query()->create(['name' => 'Admin', 'key' => 'admin']);
    globalRoleGroupWithPermission('admins', 'users:manage');
    $user = User::create();

    // No ScopeResolver bound: the default resolver returns null.
    $user->assignGlobalRole('admin');
    RoleGroupSync::syncGlobalDefaultsForRoles($user, ['admin']);
    $user->assignPermission('reports:view');

    expect($user->hasRole('admin'))->toBeTrue()
        ->and($user->hasAnyRole(['admin', 'owner']))->toBeTrue()
        ->and($user->hasPermission('users:manage'))->toBeTrue()
        ->and($user->hasPermission('reports:view'))->toBeTrue()
        ->and($user->can('users:manage'))->toBeTrue()
        ->and(User::query()->withRoleInScope('admin')->pluck('id')->all())->toBe([$user->id])
        ->and($user->getAllPermissions()->all())->toEqualCanonicalizing(['users:manage', 'reports:view']);

    $user->revokeGlobalRole('admin');
    RoleGroupSync::syncGlobalDefaultsForRoles($user, []);

    expect($user->hasRole('admin'))->toBeFalse()
        ->and($user->hasPermission('users:manage'))->toBeFalse();
});
