<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Login as user" from the admin Users module (original Reozom: temporary login link generated
 * from the administration). The admin gets a separate, short-lived token for the user; the
 * user's password and own sessions are not touched.
 */
class ImpersonationController extends Controller
{
    /** Token names look like "impersonation:{adminId}" so the admin can be identified later. */
    public const TOKEN_PREFIX = 'impersonation:';

    public const TOKEN_LIFETIME_HOURS = 2;

    public function __construct(private AdminActivityLogger $activityLogger)
    {
    }

    /**
     * Start: issue a temporary token for the target user (seller/buyer or agent, never an admin).
     */
    public function start(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        if ($user->isAdmin()) {
            return api_error('You cannot log in as another admin.', 403);
        }

        if (! $user->is_active) {
            return api_error('This user is inactive. Activate the account before logging in as them.', 422);
        }

        $token = $user->createToken(
            self::TOKEN_PREFIX.$admin->id,
            ['*'],
            now()->addHours(self::TOKEN_LIFETIME_HOURS)
        )->plainTextToken;

        $this->activityLogger->log(
            $admin,
            'impersonation_start',
            "Logged in as user #{$user->id} ({$user->email})",
            $request
        );

        return api_success([
            'token' => $token,
            'user' => new UserResource($user->loadMissing('currentAgentPackage.package')),
            'impersonatedBy' => ['id' => $admin->id, 'name' => $admin->name],
            'expiresAt' => now()->addHours(self::TOKEN_LIFETIME_HOURS)->toIso8601String(),
        ], "You are now logged in as {$user->name}.");
    }

    /**
     * Stop: delete the temporary token so it can't be used again.
     */
    public function stop(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $adminId = self::impersonatorIdFromTokenName($token?->name);

        if ($adminId === null) {
            return api_error('This session is not an admin "login as user" session.', 422);
        }

        $user = $request->user();
        $token->delete();

        $this->activityLogger->log(
            User::find($adminId),
            'impersonation_stop',
            "Stopped logging in as user #{$user->id} ({$user->email})",
            $request
        );

        return api_success(null, 'Returned to the admin account.');
    }

    /**
     * The admin behind the current request's token, or null for a normal login.
     */
    public static function impersonator(Request $request): ?User
    {
        $adminId = self::impersonatorIdFromTokenName($request->user()?->currentAccessToken()?->name);

        return $adminId ? User::find($adminId) : null;
    }

    private static function impersonatorIdFromTokenName(?string $name): ?int
    {
        if (! is_string($name) || ! Str::startsWith($name, self::TOKEN_PREFIX)) {
            return null;
        }

        $id = (int) Str::after($name, self::TOKEN_PREFIX);

        return $id > 0 ? $id : null;
    }
}
