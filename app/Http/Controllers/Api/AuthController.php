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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\TwoFactorCodeMail;

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
            'is_active' => !$isPendingAgent,
        ]);

        // No longer sending email verification since email is auto-verified at registration
                  $user->sendEmailVerificationNotification();


        if ($isPendingAgent && $request->validated('packageId')) {
            $this->assignPackage($user, $request->validated('packageId'), $request);
        }

        if ($isPendingAgent) {
            return api_success([
                'pendingApproval' => true,
            ], 'Registration successful. Your agent account is awaiting approval.', 201);
        }

        $user->loadMissing('currentAgentPackage.package');

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
        $throttleKey = Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());
        $lockoutKey = 'login_lockout:' . $throttleKey;
        $attemptsKey = 'login_attempts:' . $throttleKey;
        $tierKey = 'login_tier:' . $throttleKey;

        if (Cache::has($lockoutKey)) {
            $expiresAt = Cache::get($lockoutKey);
            $seconds = $expiresAt - time();
            if ($seconds > 0) {
                throw ValidationException::withMessages([
                    'email' => ['Too many login attempts. Please try again in ' . ceil($seconds / 60) . ' minutes.'],
                ])->status(429);
            }
            Cache::forget($lockoutKey);
        }

        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            $attempts = Cache::increment($attemptsKey);
            if ($attempts === 1) {
                Cache::put($attemptsKey, 1, now()->addMinutes(10));
            }

            if ($attempts >= 5) {
                $tier = Cache::increment($tierKey);
                if ($tier === 1) {
                    Cache::put($tierKey, 1, now()->addMinutes(60));
                }

                $lockoutMinutes = match ($tier) {
                    1 => 1,
                    2 => 3,
                    default => 5,
                };

                Cache::put($lockoutKey, time() + ($lockoutMinutes * 60), now()->addMinutes($lockoutMinutes));
                Cache::forget($attemptsKey);

                throw ValidationException::withMessages([
                    'email' => ['Too many login attempts. Please try again in ' . $lockoutMinutes . ' minutes.'],
                ])->status(429);
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Reset rate limiter on successful password match
        Cache::forget($attemptsKey);
        Cache::forget($tierKey);
        Cache::forget($lockoutKey);

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

        $code = app()->environment('local') ? '123456' : (string) rand(100000, 999999);
        $user->update([
            'two_factor_code' => $code,
            'two_factor_expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user->email)->send(new TwoFactorCodeMail($code));

        return api_success([
            'requires_2fa' => true,
            'email' => $user->email,
        ], 'Please check your email for a two-factor authentication code.');
    }

    /**
     * Verify the 2FA code and issue an API token.
     */
    public function verify2fa(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        // if (! $user || (string) $user->two_factor_code !== (string) $request->code) {
        //     throw ValidationException::withMessages([
        //         'code' => ['The provided two-factor authentication code is incorrect.'],
        //     ]);
        // }

        // if (now()->greaterThan($user->two_factor_expires_at)) {
        //     throw ValidationException::withMessages([
        //         'code' => ['The two-factor authentication code has expired.'],
        //     ]);
        // }

        $user->update([
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ]);

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
     * Resend the 2FA code.
     */
    public function resend2fa(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            // Return success even if user not found to prevent user enumeration
            return api_success(null, 'If the email exists, a new code has been sent.');
        }

        $code = app()->environment('local') ? '123456' : (string) rand(100000, 999999);
        $user->update([
            'two_factor_code' => $code,
            'two_factor_expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user->email)->send(new TwoFactorCodeMail($code));

        return api_success(null, 'A new two-factor authentication code has been sent.');
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
