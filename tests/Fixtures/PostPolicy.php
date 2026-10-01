<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Tests\Fixtures;

class PostPolicy
{
    /** @var array<int, array{ability: string, arguments: array<int, mixed>}> */
    public static array $calls = [];

    public static function reset(): void
    {
        static::$calls = [];
    }

    public function update(User $user, Post $post): bool
    {
        static::$calls[] = ['ability' => 'update', 'arguments' => [$post]];

        return (int) $post->author_id === (int) $user->getKey();
    }

    public function publish(User $user, Post $post): bool
    {
        static::$calls[] = ['ability' => 'publish', 'arguments' => [$post]];

        // Scope participates through the subject: the policy asks for the
        // permission in the scope the post belongs to, not the current one.
        return $user->hasPermission('posts:publish', $post->team_id);
    }
}
