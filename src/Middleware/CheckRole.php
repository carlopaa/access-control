<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (empty($roles)) {
            return $next($request);
        }

        if (! method_exists($user, 'hasAnyRole') || ! $user->hasAnyRole($roles)) {
            abort(403);
        }

        return $next($request);
    }
}
