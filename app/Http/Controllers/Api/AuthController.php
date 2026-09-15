<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Package;
use App\Models\User;
use App\Notifications\AgentRegisteredNotification;
use App\Services\AdminActivityLogger;
use App\Services\LoginActivityLogger;
use App\Services\PackageActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginActivityLogger $loginActivityLogger,
        private readonly AdminActivityLogger $adminActivityLogger,
        private readonly PackageActivityLogger $packageActivityLogger,
    ) {}

    /**
     * Register a new agent, seller, or buyer account (the public-facing
     * registration form). Admin accounts are always provisioned separately.
     * Agent accounts require admin approval — created inactive, with no
     * token issued, until an admin activates them from the Users page.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $role = UserRole::from($request->validated('userType'));
        $isPendingAgent = $role === UserRole::Agent;

        $firstName = $request->validated('firstName');
        $lastName = $request->validated('lastName');

        $user = User::create([
            'name' => trim("{$firstName} {$lastName}"),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'street_address' => $request->validated('streetAddress'),
            'city' => $request->validated('city'),
            'state' => $request->validated('state'),
            'zip' => $request->validated('zip'),
            'password' => Hash::make($request->validated('password')),
            'role' => $role,
            'profile_finished' => false,
            'is_active' => ! $isPendingAgent,
        ]);

        if ($isPendingAgent) {
            // Agents are always reviewed and verified by an admin — no
            // self-service verify link to send, just set expectations.
            $user->notify(new AgentRegisteredNotification);
        } else {
            $user->sendEmailVerificationNotification();
        }

        if ($isPendingAgent && $request->validated('packageId')) {
            $this->assignPackage($user, $request->validated('packageId'), $request);
        }

        $user->loadMissing('currentAgentPackage.package');

        if ($isPendingAgent) {
            return api_success([
                'user' => new UserResource($user),
                'pendingApproval' => true,
            ], 'Your agent account has been created and is pending admin approval. You will be notified once approved.', 201);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return api_success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Registration successful. Please check your email to verify your account.', 201);
    }

    /**
     * Record the agent's chosen package at signup, matching what
     * PackageController::select does for a post-login package pick.
     */
    private function assignPackage(User $user, string $packageId, Request $request): void
    {
        $package = Package::findOrFail($packageId);

        DB::transaction(function () use ($user, $package, $request) {
            $user->agentPackages()->create([
                'package_id' => $package->id,
                'started_at' => now(),
                'expires_at' => now()->addDays($package->duration_days),
            ]);

            $user->assignRole($package->role_name);

            $this->packageActivityLogger->log($user, $package->id, null, 'purchased', $request);
        });
    }

    /**
     * Authenticate an existing user and issue an API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email address before signing in.'],
            ]);
        }

        if (! $user->is_active) {
            // A never-successfully-logged-in agent is still awaiting approval;
            // login logs are only ever written past this same gate below, so
            // their absence reliably distinguishes "pending" from "deactivated
            // after having been active" without needing a separate column.
            $isPendingApproval = $user->isAgent() && ! $user->loginLogs()->exists();

            throw ValidationException::withMessages([
                'email' => [$isPendingApproval
                    ? 'Your agent account is pending admin approval.'
                    : 'Your account has been deactivated. Contact an administrator.'],
            ]);
        }

        $user->loadMissing('currentAgentPackage.package');

        $token = $user->createToken('api-token')->plainTextToken;

        $this->loginActivityLogger->log($user, $request);

        if ($user->isAdmin() || $user->isAgent()) {
            $this->adminActivityLogger->log($user, 'login', request: $request);
        }

        return api_success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Login successful.');
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin() || $user->isAgent()) {
            $this->adminActivityLogger->log($user, 'logout', request: $request);
        }

        $user->currentAccessToken()->delete();

        return api_success(null, 'Logout successful.');
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('currentAgentPackage.package');

        return api_success(new UserResource($user));
    }
}
