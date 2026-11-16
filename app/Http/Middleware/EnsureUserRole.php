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

        $isSellerBuyer = in_array(strtolower($userRole), ['seller_buyer', 'buyer_seller', 'seller/buyer', 'client', 'seller', 'buyer'], true);
        $wantsSellerBuyer = (bool) array_intersect(array_map('strtolower', $roles), ['seller_buyer', 'buyer_seller', 'seller/buyer', 'client', 'seller', 'buyer']);

        // Allow if user's role matches any allowed role, or if user is an admin
        if (
            in_array($userRole, $roles, true)
            || ($isSellerBuyer && $wantsSellerBuyer)
            || ($userRole === 'admin' && in_array('admin', $roles, true))
            || $user->isAdmin()
        ) {
            return $next($request);
        }

        return ApiResponse::error('Forbidden. Insufficient role permissions.', 403);
    }
}
