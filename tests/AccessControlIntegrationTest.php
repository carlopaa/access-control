<?php

declare(strict_types=1);

use Aapolrac\AccessControl\AccessControl;
use Aapolrac\AccessControl\Contracts\OrganizationResolver;
use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Contracts\TenantResolver;
use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Permission;
use Aapolrac\AccessControl\Models\Role;
use Aapolrac\AccessControl\Support\RoleGroupSync;
use Aapolrac\AccessControl\Tests\Fixtures\CustomGroup;
use Aapolrac\AccessControl\Tests\Fixtures\CustomPermission;
use Aapolrac\AccessControl\Tests\Fixtures\CustomRole;
use Aapolrac\AccessControl\Tests\Fixtures\Enums\MemberPermission;
use Aapolrac\AccessControl\Tests\Fixtures\OrganizationResolver as TestOrganizationResolver;
use Aapolrac\AccessControl\Tests\Fixtures\ScopeResolver as TestScopeResolver;
use Aapolrac\AccessControl\Tests\Fixtures\TenantResolver as TestTenantResolver;
use Aapolrac\AccessControl\Tests\Fixtures\User;
use Aapolrac\AccessControl\Tests\Fixtures\Wedding;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
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

    app()->bind(OrganizationResolver::class, static fn () => new TestOrganizationResolver(20));

    expect($firstUser->hasRole('owner'))->toBeTrue()
        ->and($firstUser->hasRoleInScope('owner', 10))->toBeTrue()
        ->and($firstUser->hasRoleInOrg('owner', 10))->toBeTrue()
        ->and($firstUser->hasRoleInOrg('owner', 20))->toBeFalse()
        ->and(User::query()->withRole('owner')->pluck('id')->all())->toBe([$firstUser->id])
        ->and(User::query()->withRoleInScope('manager', 20)->pluck('id')->all())->toBe([$secondUser->id])
        ->and(User::query()->withRoleInOrg('manager', 20)->pluck('id')->all())->toBe([$secondUser->id])
        ->and(User::query()->withRoleInOrg('manager')->pluck('id')->all())->toBe([$secondUser->id]);
});

it('uses the scope resolver binding when resolving the active scope', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $user = User::query()->create();

    $user->roles()->attach($owner->getKey(), ['organization_id' => 44]);

    app()->bind(ScopeResolver::class, static fn () => new TestScopeResolver(44));

    expect(User::query()->withRoleInScope('owner')->pluck('id')->all())->toBe([$user->id]);
});

it('keeps the legacy tenant resolver binding working for backward compatibility', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $user = User::query()->create();

    $user->roles()->attach($owner->getKey(), ['organization_id' => 33]);

    app()->bind(OrganizationResolver::class, static fn () => new TestTenantResolver(33));
    app()->bind(TenantResolver::class, static fn () => new TestTenantResolver(33));

    expect(User::query()->withRoleInOrg('owner')->pluck('id')->all())->toBe([$user->id]);
});

it('provides organization-scoped role and group assignment helpers', function (): void {
    $owner = Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $manager = Role::query()->create(['name' => 'Manager', 'key' => 'manager']);
    $owners = Group::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $reviewers = Group::query()->create(['name' => 'Reviewers', 'key' => 'reviewers']);
    $user = User::create();

    $user->assignRole('owner', 12)
        ->assignRole($manager->id, 12)
        ->assignGroup('owners', 12)
        ->assignGroup($reviewers->id, 12);

    expect($user->hasAnyRoleInOrg(['owner', 'manager'], 12))->toBeTrue()
        ->and($user->hasAnyRoleInScope(['owner', 'manager'], 12))->toBeTrue()
        ->and($user->groups()->wherePivot('organization_id', 12)->pluck('key')->all())
            ->toEqualCanonicalizing([$owners->key, $reviewers->key]);

    $user->syncRoles(['manager'], 12)
        ->syncGroups(['reviewers'], 12);

    expect($user->fresh()->hasRoleInOrg('owner', 12))->toBeFalse()
        ->and($user->fresh()->hasRoleInOrg('manager', 12))->toBeTrue()
        ->and($user->fresh()->groups()->wherePivot('organization_id', 12)->pluck('key')->all())
            ->toEqualCanonicalizing([$reviewers->key]);

    $user->revokeRole('manager', 12)
        ->revokeGroup('reviewers', 12);

    expect($user->fresh()->hasAnyRoleInOrg(['owner', 'manager'], 12))->toBeFalse()
        ->and($user->fresh()->groups()->wherePivot('organization_id', 12)->exists())->toBeFalse();
});

it('requires an organization for role and group assignment helpers', function (): void {
    Role::query()->create(['name' => 'Owner', 'key' => 'owner']);
    Group::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $user = User::create();

    expect(fn () => $user->assignRole('owner', null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $user->assignGroup('owners', null))
        ->toThrow(InvalidArgumentException::class);
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

it('installs config and migrations with a configurable scope setup', function (): void {
    $configDirectory = base_path('build/install-config');
    $migrationsDirectory = base_path('build/install-migrations');
    $configPath = $configDirectory.'/access_control.php';

    File::deleteDirectory($configDirectory);
    File::deleteDirectory($migrationsDirectory);

    $this->artisan('access-control:install', [
        '--scope-model' => 'App\\Models\\Team',
        '--scope-key' => 'team_id',
        '--config-path' => $configPath,
        '--migrations-path' => $migrationsDirectory,
    ])->assertSuccessful();

    expect(File::exists($configPath))->toBeTrue();

    $configContents = File::get($configPath);

    expect($configContents)->toContain("'model' => App\\Models\\Team::class,")
        ->and($configContents)->toContain("'foreign_key' => 'team_id',");

    $migrationFiles = collect(File::files($migrationsDirectory))->map->getFilename()->all();

    expect(collect($migrationFiles)->contains(fn (string $file): bool => str_ends_with($file, 'create_role_user_table.php')))->toBeTrue()
        ->and(collect($migrationFiles)->contains(fn (string $file): bool => str_ends_with($file, 'create_group_user_table.php')))->toBeTrue();
});

it('can install access control interactively with a discovered scope model', function (): void {
    $configDirectory = base_path('build/install-config-interactive');
    $migrationsDirectory = base_path('build/install-migrations-interactive');
    $configPath = $configDirectory.'/access_control.php';
    $modelsPath = app_path('Models');
    $modelFilePath = $modelsPath.'/Team.php';
    $appNamespace = app()->getNamespace();

    File::deleteDirectory($configDirectory);
    File::deleteDirectory($migrationsDirectory);
    File::ensureDirectoryExists($modelsPath);
    File::put($modelFilePath, <<<PHP
<?php

namespace {$appNamespace}Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model {}
PHP);
    require_once $modelFilePath;

    try {
        $this->artisan('access-control:install', [
            '--config-path' => $configPath,
            '--migrations-path' => $migrationsDirectory,
        ])
            ->expectsSearch(
                'What model should access control use as its scope? (Optional)',
                $appNamespace.'Models\\Team',
                'Team',
                [
                    '__none' => 'No scope model',
                    '__custom' => 'Custom model class',
                    $appNamespace.'Models\\Team' => 'Team',
                ],
            )
            ->expectsQuestion('What foreign key should be used on the pivot tables?', 'team_id')
            ->assertSuccessful();
    } finally {
        File::delete($modelFilePath);
    }

    $configContents = File::get($configPath);

    expect($configContents)->toContain("'model' => {$appNamespace}Models\\Team::class,")
        ->and($configContents)->toContain("'foreign_key' => 'team_id',");
});

it('generates a permissions enum for a resource', function (): void {
    $directory = base_path('build/generated-enums');
    $filePath = $directory.'/CustomerPermission.php';

    File::deleteDirectory($directory);

    $this->artisan('access-control:make-enum', [
        'name' => 'CustomerPermission',
        '--resource' => 'customer',
        '--path' => $directory,
    ])
        ->expectsOutputToContain('Permission enum created')
        ->assertSuccessful();

    expect(File::exists($filePath))->toBeTrue();

    $contents = File::get($filePath);

    expect($contents)->toContain("enum CustomerPermission: string")
        ->and($contents)->toContain("case ALLOW_VIEW = 'customer:view';")
        ->and($contents)->toContain("case ALLOW_DELETE_ANY = 'customer:delete-any';")
        ->and($contents)->not->toContain('DENY_VIEW');
});

it('can generate a permissions enum interactively', function (): void {
    $directory = base_path('build/generated-enums-interactive');
    $filePath = $directory.'/WeddingPermission.php';
    $modelsPath = app_path('Models');
    $modelFilePath = $modelsPath.'/Guest.php';
    $appNamespace = app()->getNamespace();

    File::deleteDirectory($directory);
    File::ensureDirectoryExists($modelsPath);
    File::put($modelFilePath, <<<PHP
<?php

namespace {$appNamespace}Models;

use Illuminate\Database\Eloquent\Model;

class Guest extends Model {}
PHP);
    require_once $modelFilePath;

    try {
        $this->artisan('access-control:make-enum', [
            '--path' => $directory,
        ])
            ->expectsQuestion('What should the enum be named?', 'WeddingPermission')
            ->expectsSearch(
                'What model should these permissions apply to?',
                'Guest',
                'Guest',
                [
                    '__custom' => 'Custom resource',
                    $appNamespace.'Models\\Guest' => 'Guest',
                ],
            )
            ->expectsConfirmation('Include deny permissions too?', 'yes')
            ->assertSuccessful();
    } finally {
        File::delete($modelFilePath);
    }

    $contents = File::get($filePath);

    expect($contents)->toContain("enum WeddingPermission: string")
        ->and($contents)->not->toContain('declare(strict_types=1);')
        ->and($contents)->toContain("case ALLOW_VIEW = 'guest:view';")
        ->and($contents)->toContain("case DENY_VIEW = 'guest:view:deny';");
});

it('can include deny cases when generating a permissions enum', function (): void {
    $directory = base_path('build/generated-enums-with-deny');
    $filePath = $directory.'/CustomerPermission.php';

    File::deleteDirectory($directory);

    $this->artisan('access-control:make-enum', [
        'name' => 'CustomerPermission',
        '--resource' => 'customer',
        '--path' => $directory,
        '--deny' => true,
    ])->assertSuccessful();

    $contents = File::get($filePath);

    expect($contents)->toContain("case DENY_VIEW = 'customer:view:deny';")
        ->and($contents)->toContain("case DENY_DELETE_ANY = 'customer:delete-any:deny';");
});

it('does not overwrite an existing permissions enum unless forced', function (): void {
    $directory = base_path('build/generated-enums-protected');
    $filePath = $directory.'/CustomerPermission.php';

    File::deleteDirectory($directory);
    File::ensureDirectoryExists($directory);
    File::put($filePath, 'original');

    $this->artisan('access-control:make-enum', [
        'name' => 'CustomerPermission',
        '--resource' => 'customer',
        '--path' => $directory,
    ])->assertFailed();

    expect(File::get($filePath))->toBe('original');

    $this->artisan('access-control:make-enum', [
        'name' => 'CustomerPermission',
        '--resource' => 'customer',
        '--path' => $directory,
        '--force' => true,
    ])->assertSuccessful();

    expect(File::get($filePath))->toContain("case ALLOW_VIEW = 'customer:view';");
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
    config()->set('access_control.scope', [
        'model' => Wedding::class,
        'foreign_key' => 'wedding_id',
    ]);
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
    Schema::create('weddings', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    $role = CustomRole::query()->create(['name' => 'Owner', 'key' => 'owner']);
    $group = CustomGroup::query()->create(['name' => 'Owners', 'key' => 'owners']);
    $permission = CustomPermission::query()->create(['name' => 'reports:view']);
    $wedding = Wedding::query()->create(['name' => 'A & B']);
    $user = User::create();

    $group->permissions()->attach($permission);
    $user->assignRoleInScope($role->id, $wedding);
    $user->assignGroupInScope($group->id, $wedding);

    expect($user->roles()->getRelated()::class)->toBe(CustomRole::class)
        ->and($user->groups()->getRelated()::class)->toBe(CustomGroup::class)
        ->and((new CustomRole)->getTable())->toBe('acl_roles')
        ->and((new CustomGroup)->getTable())->toBe('acl_groups')
        ->and((new CustomPermission)->getTable())->toBe('acl_permissions')
        ->and(config('access_control.scope.model'))->toBe(Wedding::class)
        ->and(config('access_control.scope.foreign_key'))->toBe('wedding_id')
        ->and($user->hasRoleInScope('owner', (int) $wedding->getKey()))->toBeTrue()
        ->and($user->hasPermission('reports:view'))->toBeTrue()
        ->and(User::query()->withRoleInScope('owner', $wedding)->pluck('id')->all())->toBe([$user->id]);

    RoleGroupSync::syncDefaultsForRoles($user, $wedding, ['owner']);

    expect($user->fresh()->groups()->wherePivot('wedding_id', (int) $wedding->getKey())->pluck('key')->all())
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
        $table->unsignedBigInteger('wedding_id');
        $table->timestamps();
    });

    Schema::create('acl_group_user', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('group_id');
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('wedding_id')->nullable();
        $table->timestamps();
    });

    Schema::create('acl_group_permission', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('group_id');
        $table->unsignedBigInteger('permission_id');
        $table->timestamps();
    });
}
