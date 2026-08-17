<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AdminActivityLogger;
use App\Services\LoginActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginActivityLogger $loginActivityLogger,
        private readonly AdminActivityLogger $adminActivityLogger,
    ) {}

    /**
     * Register a new seller account (the public-facing registration form).
     * Agent/admin accounts are provisioned separately.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('fullName'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => Hash::make($request->validated('password')),
            'role' => UserRole::Seller,
            'profile_finished' => false,
        ]);

        $user->sendEmailVerificationNotification();

        $token = $user->createToken('api-token')->plainTextToken;

        return api_success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Registration successful. Please check your email to verify your account.', 201);
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
        return api_success(new UserResource($request->user()));
    }
}
