<?php

declare(strict_types=1);

use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Role;
use Aapolrac\AccessControl\Support\RoleGroupSync;
use Aapolrac\AccessControl\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

/** @return array<int, array{group_id: int, organization_id: int|null}> */
function groupRows(User $user): array
{
    return DB::table('group_user')
        ->where('user_id', $user->getKey())
        ->orderBy('id')
        ->get()
        ->map(static fn ($row) => ['group_id' => (int) $row->group_id, 'organization_id' => $row->organization_id === null ? null : (int) $row->organization_id])
        ->all();
}

/** @return array<int, array{role_id: int, organization_id: int}> */
function roleRows(User $user): array
{
    return DB::table('role_user')
        ->where('user_id', $user->getKey())
        ->orderBy('id')
        ->get()
        ->map(static fn ($row) => ['role_id' => (int) $row->role_id, 'organization_id' => (int) $row->organization_id])
        ->all();
}

it('assigning the same group in a second scope adds a row instead of moving the first one', function (): void {
    $user = User::create();
    $editors = Group::query()->create(['name' => 'Editors', 'key' => 'editors']);

    $user->assignGroup('editors', 5);
    $user->assignGroup('editors', 12);
    $user->assignGroup('editors', 12); // idempotent

    expect(groupRows($user))->toBe([
        ['group_id' => $editors->id, 'organization_id' => 5],
        ['group_id' => $editors->id, 'organization_id' => 12],
    ]);
});

it('assigning the same role in a second scope adds a row instead of moving the first one', function (): void {
    $user = User::create();
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);

    $user->assignRole('owner', 5);
    $user->assignRole($owner->id, 12);
    $user->assignRole('owner', 5); // idempotent

    expect(roleRows($user))->toBe([
        ['role_id' => $owner->id, 'organization_id' => 5],
        ['role_id' => $owner->id, 'organization_id' => 12],
    ]);
});

it('syncs groups within one scope without touching other scopes or global rows', function (): void {
    $user = User::create();
    $editors = Group::query()->create(['name' => 'Editors', 'key' => 'editors']);
    $reviewers = Group::query()->create(['name' => 'Reviewers', 'key' => 'reviewers']);
    $staff = Group::query()->create(['name' => 'Staff', 'key' => 'staff']);

    $user->assignGroups(['editors', 'reviewers'], 1);
    $user->assignGroup('editors', 2);
    $user->assignGlobalGroup('staff');

    $user->syncGroups(['reviewers'], 1);

    expect(groupRows($user))->toEqualCanonicalizing([
        ['group_id' => $reviewers->id, 'organization_id' => 1],
        ['group_id' => $editors->id, 'organization_id' => 2],
        ['group_id' => $staff->id, 'organization_id' => null],
    ]);

    $user->syncGroups([], 2);

    expect(groupRows($user))->toEqualCanonicalizing([
        ['group_id' => $reviewers->id, 'organization_id' => 1],
        ['group_id' => $staff->id, 'organization_id' => null],
    ]);
});

it('revokes a group only in the given scope', function (): void {
    $user = User::create();
    $editors = Group::query()->create(['name' => 'Editors', 'key' => 'editors']);

    $user->assignGroup('editors', 1);
    $user->assignGroup('editors', 2);
    $user->assignGlobalGroup('editors');

    $user->revokeGroup('editors', 1);

    expect(groupRows($user))->toEqualCanonicalizing([
        ['group_id' => $editors->id, 'organization_id' => 2],
        ['group_id' => $editors->id, 'organization_id' => null],
    ]);
});

it('syncs and revokes roles within one scope only', function (): void {
    $user = User::create();
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $manager = Role::query()->create(['name' => 'Manager', 'key' => 'manager']);

    $user->assignRoles(['owner', 'manager'], 1);
    $user->assignRole('owner', 2);

    $user->syncRoles(['manager'], 1);

    expect(roleRows($user))->toEqualCanonicalizing([
        ['role_id' => $manager->id, 'organization_id' => 1],
        ['role_id' => $owner->id, 'organization_id' => 2],
    ]);

    $user->revokeRole('owner', 1); // not present in scope 1: no-op

    expect(roleRows($user))->toHaveCount(2);

    $user->revokeRole('owner', 2);

    expect(roleRows($user))->toBe([
        ['role_id' => $manager->id, 'organization_id' => 1],
    ]);
});

it('manages global group membership explicitly and independently of scoped rows', function (): void {
    $user = User::create();
    $staff = Group::query()->create(['name' => 'Staff', 'key' => 'staff']);
    $editors = Group::query()->create(['name' => 'Editors', 'key' => 'editors']);

    $user->assignGroup('staff', 1);
    $user->assignGlobalGroups(['staff', 'editors']);
    $user->assignGlobalGroup('staff'); // idempotent

    expect(groupRows($user))->toEqualCanonicalizing([
        ['group_id' => $staff->id, 'organization_id' => 1],
        ['group_id' => $staff->id, 'organization_id' => null],
        ['group_id' => $editors->id, 'organization_id' => null],
    ]);

    $user->syncGlobalGroups(['editors']);

    expect(groupRows($user))->toEqualCanonicalizing([
        ['group_id' => $staff->id, 'organization_id' => 1],
        ['group_id' => $editors->id, 'organization_id' => null],
    ]);

    $user->revokeGlobalGroups(['editors']);

    expect(groupRows($user))->toBe([
        ['group_id' => $staff->id, 'organization_id' => 1],
    ]);
});

it('keeps role-to-group defaults scoped when syncing the same user across scopes', function (): void {
    config()->set('access_control.groups', [
        'owner' => ['owners', 'team-management'],
        'manager' => ['team-management'],
    ]);

    $owners = Group::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $teamManagement = Group::query()->create(['name' => 'Team Management', 'key' => 'team-management']);
    $staff = Group::query()->create(['name' => 'Staff', 'key' => 'staff']);
    $user = User::create();

    $user->assignGlobalGroup('staff');

    RoleGroupSync::syncDefaultsForRoles($user, 1, ['owner']);
    RoleGroupSync::syncDefaultsForRoles($user, 2, ['manager']);

    expect(groupRows($user))->toEqualCanonicalizing([
        ['group_id' => $staff->id, 'organization_id' => null],
        ['group_id' => $owners->id, 'organization_id' => 1],
        ['group_id' => $teamManagement->id, 'organization_id' => 1],
        ['group_id' => $teamManagement->id, 'organization_id' => 2],
    ]);

    // Demoting in scope 1 leaves scope 2 and the global row untouched.
    RoleGroupSync::syncDefaultsForRoles($user, 1, ['manager']);

    expect(groupRows($user))->toEqualCanonicalizing([
        ['group_id' => $staff->id, 'organization_id' => null],
        ['group_id' => $teamManagement->id, 'organization_id' => 1],
        ['group_id' => $teamManagement->id, 'organization_id' => 2],
    ]);

    // Idempotent.
    RoleGroupSync::syncDefaultsForRoles($user, 1, ['manager']);
    RoleGroupSync::attach($user, 2, ['team-management']);

    expect(groupRows($user))->toHaveCount(3);
});
