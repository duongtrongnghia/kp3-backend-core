<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate middleware.
 *
 * Permission module decoupled: super-admin bypass now uses User::isSuperAdmin()
 * (role === 'admin') — no PermissionService DB lookup needed.
 *
 * Usage: middleware('role:admin') — passes if user->role === 'admin'
 *        middleware('role:admin,customer') — passes if user->role is in the list
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->role) {
            return response()->json([
                'message' => __('api.auth.unauthorized_role'),
            ], 403);
        }

        // Super-admin bypass — admin role passes any role:* gate.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // User::$role is a plain string (not a BackedEnum); cast is sufficient.
        $userRole = (string) $user->role;

        if (! in_array($userRole, $roles, true)) {
            return response()->json([
                'message' => __('api.auth.unauthorized_role'),
            ], 403);
        }

        return $next($request);
    }
}
