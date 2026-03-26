<?php

declare(strict_types=1);

namespace Aapolrac\AccessControl\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (empty($permissions)) {
            return $next($request);
        }

        if (! method_exists($user, 'hasAnyPermission') || ! $user->hasAnyPermission($permissions)) {
            abort(403);
        }

        return $next($request);
    }
}
