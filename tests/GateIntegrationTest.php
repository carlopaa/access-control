<?php

declare(strict_types=1);

use Aapolrac\AccessControl\Contracts\ScopeResolver;
use Aapolrac\AccessControl\Models\Group;
use Aapolrac\AccessControl\Models\Permission;
use Aapolrac\AccessControl\Support\GateRegistrar;
use Aapolrac\AccessControl\Tests\Fixtures\Enums\MemberPermission;
use Aapolrac\AccessControl\Tests\Fixtures\Post;
use Aapolrac\AccessControl\Tests\Fixtures\PostPolicy;
use Aapolrac\AccessControl\Tests\Fixtures\SwitchableScopeResolver;
use Aapolrac\AccessControl\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('posts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('author_id')->nullable();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->timestamps();
    });

    PostPolicy::reset();
});

it('grants an ability without arguments from a held permission', function (): void {
    $user = User::create();
    $user->assignPermission('reports:view');

    expect($user->can('reports:view'))->toBeTrue()
        ->and($user->can('reports:export'))->toBeFalse()
        ->and(Gate::forUser($user)->allows('reports:view'))->toBeTrue()
        ->and(Gate::forUser($user)->denies('reports:view'))->toBeFalse();
});

it('accepts enum abilities', function (): void {
    $user = User::create();
    $user->assignPermission(MemberPermission::ViewAny);

    expect($user->can(MemberPermission::ViewAny))->toBeTrue()
        ->and($user->can(MemberPermission::Update))->toBeFalse();
});

it('preserves the ability name and arguments for a defined ability instead of bypassing it', function (): void {
    $user = User::create();
    $user->assignPermission('posts:delete');
    $post = Post::query()->create(['author_id' => 999]);

    $received = [];

    Gate::define('posts:delete', function (User $gateUser, Post $gatePost) use (&$received): bool {
        $received = ['ability' => 'posts:delete', 'user' => $gateUser->getKey(), 'post' => $gatePost->getKey()];

        return false;
    });

    expect($user->can('posts:delete', $post))->toBeFalse()
        ->and($received)->toBe(['ability' => 'posts:delete', 'user' => $user->getKey(), 'post' => $post->getKey()])
        // Without arguments the permission still answers directly.
        ->and($user->can('posts:delete'))->toBeTrue();
});

it('lets a policy decide when the ability targets a model, passing the target through', function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $user = User::create();
    $user->assignPermission('update'); // name collision with the policy method must not bypass it
    $own = Post::query()->create(['author_id' => $user->getKey()]);
    $foreign = Post::query()->create(['author_id' => $user->getKey() + 1]);

    expect($user->can('update', $own))->toBeTrue()
        ->and($user->can('update', $foreign))->toBeFalse()
        ->and(PostPolicy::$calls)->toHaveCount(2)
        ->and(PostPolicy::$calls[0]['arguments'][0]->is($own))->toBeTrue()
        ->and(PostPolicy::$calls[1]['arguments'][0]->is($foreign))->toBeTrue();
});

it('lets a policy make the target\'s scope participate in a scoped permission check', function (): void {
    Gate::policy(Post::class, PostPolicy::class);
    app()->instance(ScopeResolver::class, new SwitchableScopeResolver(1));

    $user = User::create();
    $publishers = Group::query()->create(['name' => 'Publishers', 'key' => 'publishers']);
    $publishers->permissions()->attach(Permission::query()->create(['name' => 'posts:publish']));
    $user->assignGroup('publishers', 1);

    $postInScopeOne = Post::query()->create(['team_id' => 1]);
    $postInScopeTwo = Post::query()->create(['team_id' => 2]);

    // Current scope is 1, so a bare permission check passes...
    expect($user->hasPermission('posts:publish'))->toBeTrue()
        // ...but the policy evaluates the permission in the post's own scope.
        ->and($user->can('publish', $postInScopeOne))->toBeTrue()
        ->and($user->can('publish', $postInScopeTwo))->toBeFalse();
});

it('falls back to the permission check when nothing else handles an ability with arguments', function (): void {
    $user = User::create();
    $user->assignPermission('posts:feature');
    $post = Post::query()->create();

    expect($user->can('posts:feature', $post))->toBeTrue()
        ->and($user->can('posts:archive', $post))->toBeFalse();
});

it('does not pre-empt a policy that lacks the ability method but has a defined ability', function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $user = User::create();
    $user->assignPermission('posts:feature');
    $post = Post::query()->create();

    $received = null;
    Gate::define('posts:feature', function (User $gateUser, Post $gatePost) use (&$received): bool {
        $received = $gatePost->getKey();

        return true;
    });

    expect($user->can('posts:feature', $post))->toBeTrue()
        ->and($received)->toBe($post->getKey());
});

it('registers enum permissions as gate abilities that receive their arguments', function (): void {
    GateRegistrar::registerPermissions(MemberPermission::class);

    $user = User::create();
    $user->assignPermission(MemberPermission::ViewAny);
    $post = Post::query()->create();

    expect(Gate::has(MemberPermission::ViewAny->value))->toBeTrue()
        ->and($user->can(MemberPermission::ViewAny->value, $post))->toBeTrue()
        ->and($user->can(MemberPermission::Update->value, $post))->toBeFalse();
});

it('never grants anything to guests', function (): void {
    expect(Gate::allows('reports:view'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('reports:view'))->toBeFalse();
});

it('resolves gate abilities in the current scope', function (): void {
    $resolver = new SwitchableScopeResolver(1);
    app()->instance(ScopeResolver::class, $resolver);

    $user = User::create();
    $group = Group::query()->create(['name' => 'Analysts', 'key' => 'analysts']);
    $group->permissions()->attach(Permission::query()->create(['name' => 'reports:view']));
    $user->assignGroup('analysts', 1);

    expect($user->can('reports:view'))->toBeTrue();

    $resolver->scopeId = 2;

    expect($user->can('reports:view'))->toBeFalse();
});
