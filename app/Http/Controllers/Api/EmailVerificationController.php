<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\AgentRegisteredNotification;
use App\Notifications\AgentVerifiedNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Handle the signed link from the verification email. This is opened
     * directly in the browser from an email client with no Sanctum bearer
     * token attached, so — unlike the rest of the API — it deliberately does
     * not sit behind auth:sanctum. Identity comes from the signed URL itself
     * (the `signed` middleware) plus the id/hash match below, the same
     * check Laravel's built-in EmailVerificationRequest performs.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return redirect()->away("{$frontendUrl}/auth/email-verified?status=invalid");
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $user->notify($user->role === UserRole::Agent ? new AgentVerifiedNotification : new WelcomeNotification);
        }

        return redirect()->away("{$frontendUrl}/auth/email-verified?status=verified");
    }

    /**
     * Resend the verification email to an authenticated but unverified user.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return api_success(null, 'Email is already verified.');
        }

        $this->sendPendingNotification($user);

        return api_success(null, 'Verification email sent.');
    }

    /**
     * Resend the verification email for a not-yet-authenticated user, given
     * their credentials-free identifier (email). Used from the login screen
     * when a login attempt fails specifically because the email isn't
     * verified yet.
     */
    public function resendForEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->string('email'))->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            $this->sendPendingNotification($user);
        }

        // Always respond success — do not reveal whether the email exists.
        return api_success(null, 'If that account exists and is unverified, a new link has been sent.');
    }

    /**
     * Agents never get a self-service verify link — only an admin can
     * verify them from the Users page — so a "resend" for an agent just
     * re-sends the "you're pending review" notice instead.
     */
    private function sendPendingNotification(User $user): void
    {
        if ($user->role === UserRole::Agent) {
            $user->notify(new AgentRegisteredNotification);
        } else {
            $user->sendEmailVerificationNotification();
        }
    }
}
