<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Handle an incoming request and ensure the user possesses the required business role.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401);
        }

        $userRole = $user->role instanceof UserRole
            ? $user->role->value
            : (string) $user->role;

        // Allow if user's role matches any allowed role, or if user is an admin
        if (in_array($userRole, $roles, true) || ($userRole === 'admin' && in_array('admin', $roles, true))) {
            return $next($request);
        }

        return ApiResponse::error('Forbidden. Insufficient role permissions.', 403);
    }
}
