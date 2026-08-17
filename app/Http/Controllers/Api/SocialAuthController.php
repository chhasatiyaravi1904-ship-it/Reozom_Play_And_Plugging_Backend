<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\LoginActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class SocialAuthController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['facebook', 'linkedin-openid', 'twitter'];

    public function __construct(private readonly LoginActivityLogger $loginActivityLogger) {}

    /**
     * Redirect the browser to the OAuth provider. All three providers are
     * configured for OAuth2 (see config/services.php), so this can stay
     * stateless — no session cookie needs to survive the redirect round
     * trip, which keeps it working cleanly alongside the token-based API.
     */
    public function redirect(string $provider): RedirectResponse
    {
        $this->ensureSupportedProvider($provider);

        return Socialite::driver($provider)->stateless()->redirect();
    }

    /**
     * Handle the provider's callback: find-or-create the user, log them in
     * with a Sanctum token, and hand off to the SPA via a redirect carrying
     * the token — there is no bearer token yet for the SPA to send, since
     * this whole exchange happened in a plain browser navigation.
     */
    public function callback(string $provider): RedirectResponse
    {
        $this->ensureSupportedProvider($provider);

        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (InvalidStateException) {
            return redirect()->away("{$frontendUrl}/auth/login?error=social_login_failed");
        }

        if (! $socialUser->getEmail()) {
            return redirect()->away("{$frontendUrl}/auth/login?error=social_login_no_email");
        }

        $socialAccount = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $socialUser->getId())
            ->first();

        $user = $socialAccount?->user ?? User::where('email', $socialUser->getEmail())->first();

        if (! $user) {
            $user = User::create([
                'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'Reozom User',
                'email' => $socialUser->getEmail(),
                'password' => Hash::make(Str::random(32)),
                'role' => UserRole::Seller,
                'profile_finished' => false,
                // Social providers already confirm the email address.
                'email_verified_at' => now(),
            ]);
        }

        if (! $socialAccount) {
            $user->socialAccounts()->create([
                'provider' => $provider,
                'provider_user_id' => $socialUser->getId(),
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;
        $this->loginActivityLogger->log($user, request(), $provider);

        return redirect()->away("{$frontendUrl}/auth/callback?token={$token}");
    }

    private function ensureSupportedProvider(string $provider): void
    {
        abort_unless(in_array($provider, self::SUPPORTED_PROVIDERS, true), 404);
    }
}
