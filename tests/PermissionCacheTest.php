<?php

declare(strict_types=1);

use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Permission;
use Aapolrac\AccessControl\Support\RoleGroupSync;
use Aapolrac\AccessControl\Tests\Fixtures\SwitchableScopeResolver;
use Aapolrac\AccessControl\Tests\Fixtures\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

function cacheGrant(User $user, string $groupKey, string $permission, ?int $scopeId): Group
{
    $group = Group::query()->firstOrCreate(['key' => $groupKey], ['name' => ucfirst($groupKey)]);
    $permissionModel = Permission::query()->firstOrCreate(['name' => $permission]);

    $group->permissions()->syncWithoutDetaching([$permissionModel->getKey()]);
    $user->groups()->attach($group->getKey(), ['organization_id' => $scopeId]);

    return $group;
}

function cacheUseScope(?int $scopeId): SwitchableScopeResolver
{
    $resolver = new SwitchableScopeResolver($scopeId);

    app()->instance(ScopeResolver::class, $resolver);

    return $resolver;
}

function cacheQueryCount(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

function cacheKeyFor(User $user): string
{
    return 'permissions:'.User::class.':'.$user->getKey();
}

it('populates the context cache for the authenticated user and reuses it', function (): void {
    $user = User::create();
    cacheGrant($user, 'staff', 'reports:view', null);

    $this->actingAs($user);

    $first = cacheQueryCount(fn () => $user->hasPermission('reports:view'));
    $second = cacheQueryCount(fn () => $user->hasPermission('reports:view'));

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and(Context::hasHidden(cacheKeyFor($user)))->toBeTrue()
        ->and(Context::getHidden(cacheKeyFor($user)))->toBe(['global' => ['reports:view']]);
});

it('does not cache users other than the authenticated one', function (): void {
    $authenticated = User::create();
    $other = User::create();
    cacheGrant($other, 'staff', 'reports:view', null);

    $this->actingAs($authenticated);

    $other->hasPermission('reports:view');

    expect(Context::hasHidden(cacheKeyFor($other)))->toBeFalse()
        ->and(cacheQueryCount(fn () => $other->hasPermission('reports:view')))->toBeGreaterThan(0);
});

it('isolates cached permissions between users when authentication switches', function (): void {
    $alice = User::create();
    $bob = User::create();
    $alice->assignPermission('secret:read');

    $this->actingAs($alice);
    expect($alice->hasPermission('secret:read'))->toBeTrue();

    $this->actingAs($bob);

    expect($bob->hasPermission('secret:read'))->toBeFalse()
        ->and(Context::hasHidden(cacheKeyFor($alice)))->toBeTrue()
        ->and(Context::getHidden(cacheKeyFor($bob)))->toBe(['global' => []]);

    // Switching back still yields Alice's own permissions.
    $this->actingAs($alice);
    expect($alice->hasPermission('secret:read'))->toBeTrue();
});

it('isolates cached permissions between scopes and when switching scope', function (): void {
    $user = User::create();
    cacheGrant($user, 'group-a', 'reports:view', 1);
    cacheGrant($user, 'group-b', 'invoices:view', 2);

    $this->actingAs($user);
    $resolver = cacheUseScope(1);

    expect($user->hasPermission('reports:view'))->toBeTrue()
        ->and($user->hasPermission('invoices:view'))->toBeFalse();

    $resolver->scopeId = 2;

    expect($user->hasPermission('reports:view'))->toBeFalse()
        ->and($user->hasPermission('invoices:view'))->toBeTrue();

    $resolver->scopeId = null;

    expect($user->hasPermission('reports:view'))->toBeFalse()
        ->and($user->hasPermission('invoices:view'))->toBeFalse()
        ->and(Context::getHidden(cacheKeyFor($user)))->toBe([
            'scope:1' => ['reports:view'],
            'scope:2' => ['invoices:view'],
            'global' => [],
        ]);

    // Cached scope lists stay independent of the resolver's current answer.
    $resolver->scopeId = 1;
    expect(cacheQueryCount(fn () => $user->hasPermission('reports:view')))->toBe(0)
        ->and($user->hasPermission('reports:view'))->toBeTrue();
});

it('keeps explicit-scope results separate from the current-scope result', function (): void {
    $user = User::create();
    cacheGrant($user, 'group-a', 'reports:view', 1);

    $this->actingAs($user);
    cacheUseScope(2);

    expect($user->hasPermission('reports:view'))->toBeFalse()
        ->and($user->hasPermission('reports:view', 1))->toBeTrue()
        ->and($user->hasPermission('reports:view'))->toBeFalse();
});

it('invalidates the cache when direct permissions change', function (): void {
    $user = User::create();
    $this->actingAs($user);

    expect($user->hasPermission('reports:view'))->toBeFalse();

    $user->assignPermission('reports:view');
    expect($user->hasPermission('reports:view'))->toBeTrue();

    $user->revokePermission('reports:view');
    expect($user->hasPermission('reports:view'))->toBeFalse();

    $user->syncDirectPermissions(['invoices:view']);
    expect($user->hasPermission('invoices:view'))->toBeTrue();

    $user->clearDirectPermissions();
    expect($user->hasPermission('invoices:view'))->toBeFalse();
});

it('invalidates the cache when group assignments change through the trait', function (): void {
    $user = User::create();
    $group = Group::query()->create(['name' => 'Editors', 'key' => 'editors']);
    $group->permissions()->attach(Permission::query()->create(['name' => 'posts:edit']));

    $this->actingAs($user);
    cacheUseScope(1);

    expect($user->hasPermission('posts:edit'))->toBeFalse();

    $user->assignGroup('editors', 1);
    expect($user->hasPermission('posts:edit'))->toBeTrue();

    $user->revokeGroup('editors', 1);
    expect($user->hasPermission('posts:edit'))->toBeFalse();

    $user->syncGroups(['editors'], 1);
    expect($user->hasPermission('posts:edit'))->toBeTrue();

    $user->syncGroups([], 1);
    expect($user->hasPermission('posts:edit'))->toBeFalse();

    $user->assignGlobalGroup('editors');
    expect($user->hasPermission('posts:edit'))->toBeTrue();

    $user->revokeGlobalGroup('editors');
    expect($user->hasPermission('posts:edit'))->toBeFalse();
});

it('invalidates the cache when RoleGroupSync changes group membership', function (): void {
    config()->set('access_control.groups', ['owner' => ['owners']]);

    $user = User::create();
    $group = Group::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $group->permissions()->attach(Permission::query()->create(['name' => 'billing:manage']));

    $this->actingAs($user);
    cacheUseScope(5);

    expect($user->hasPermission('billing:manage'))->toBeFalse();

    RoleGroupSync::syncDefaultsForRoles($user, 5, ['owner']);
    expect($user->hasPermission('billing:manage'))->toBeTrue();

    RoleGroupSync::syncDefaultsForRoles($user, 5, []);
    expect($user->hasPermission('billing:manage'))->toBeFalse();

    RoleGroupSync::attach($user, 5, ['owners']);
    expect($user->hasPermission('billing:manage'))->toBeTrue();
});

it('can be flushed explicitly for changes made outside the package helpers', function (): void {
    $user = User::create();
    $group = cacheGrant($user, 'staff', 'reports:view', null);

    $this->actingAs($user);

    expect($user->hasPermission('reports:view'))->toBeTrue();

    // Raw pivot change the package cannot observe.
    $user->groups()->detach($group->getKey());

    expect($user->hasPermission('reports:view'))->toBeTrue();

    $user->flushPermissionCache();

    expect($user->hasPermission('reports:view'))->toBeFalse()
        ->and(Context::hasHidden(cacheKeyFor($user)))->toBeTrue();
});

it('invalidating one user never touches another user\'s cache entry', function (): void {
    $alice = User::create();
    $bob = User::create();
    $alice->assignPermission('secret:read');

    $this->actingAs($alice);
    $alice->hasPermission('secret:read');

    $this->actingAs($bob);
    $bob->hasPermission('secret:read');

    $bob->assignPermission('other:thing');

    expect(Context::hasHidden(cacheKeyFor($alice)))->toBeTrue()
        ->and(Context::hasHidden(cacheKeyFor($bob)))->toBeFalse();
});

it('does nothing when the context cache is disabled', function (): void {
    config()->set('access_control.context_cache.enabled', false);

    $user = User::create();
    cacheGrant($user, 'staff', 'reports:view', null);

    $this->actingAs($user);

    expect($user->hasPermission('reports:view'))->toBeTrue()
        ->and(Context::allHidden())->toBe([])
        ->and(cacheQueryCount(fn () => $user->hasPermission('reports:view')))->toBeGreaterThan(0);
});

it('uses the configured key as the cache prefix', function (): void {
    config()->set('access_control.context_cache.key', 'acl');

    $user = User::create();
    $this->actingAs($user);
    $user->hasPermission('anything');

    expect(Context::hasHidden('acl:'.User::class.':'.$user->getKey()))->toBeTrue();
});
