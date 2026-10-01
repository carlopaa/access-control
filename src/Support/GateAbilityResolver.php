<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;

/**
 * Gate "before" hook that answers abilities with package permissions.
 *
 * Rules:
 *
 * - The user model must expose hasPermission() (HasAccessControl).
 * - Without ability arguments, a held permission grants the ability; otherwise the
 *   hook abstains (returns null) so Laravel continues with its normal resolution.
 * - With ability arguments (for example `$user->can('posts.update', $post)`), the
 *   hook abstains whenever a policy for the first argument can handle the ability
 *   or the ability has been defined on the Gate. The ability name and all of its
 *   arguments then reach that policy or callback untouched. Only when nothing else
 *   can handle the ability does the package fall back to the permission check.
 *
 * The hook never returns false, so it can never deny what a policy would allow.
 */
class GateAbilityResolver
{
    public function __construct(protected Gate $gate) {}

    public function __invoke(mixed $user, string $ability, array $arguments = []): ?bool
    {
        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            return null;
        }

        if ($arguments !== [] && $this->isHandledByPolicyOrGate($ability, $arguments)) {
            return null;
        }

        return $user->hasPermission($ability) ? true : null;
    }

    protected function isHandledByPolicyOrGate(string $ability, array $arguments): bool
    {
        if ($this->gate->has($ability)) {
            return true;
        }

        $subject = $arguments[0] ?? null;

        if ($subject === null || (! is_object($subject) && ! is_string($subject))) {
            return false;
        }

        $policy = $this->gate->getPolicyFor($subject);

        if ($policy === null) {
            return false;
        }

        return is_callable([$policy, $this->abilityMethod($ability)]);
    }

    /**
     * Mirrors Illuminate\Auth\Access\Gate::formatAbilityToMethod().
     */
    protected function abilityMethod(string $ability): string
    {
        return str_contains($ability, '-') ? Str::camel($ability) : $ability;
    }
}
