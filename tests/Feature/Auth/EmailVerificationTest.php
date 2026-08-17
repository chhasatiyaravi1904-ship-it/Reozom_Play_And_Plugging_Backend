<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rejected_for_an_unverified_email(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'jane@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    }

    public function test_visiting_the_signed_verification_link_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('status=verified', $response->headers->get('Location'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_invalid_hash_does_not_verify_the_email(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('not-the-real-email'),
        ]);

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('status=invalid', $response->headers->get('Location'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verified_user_can_login_after_visiting_the_link(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'jane@example.com']);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->get($url);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
    }

    public function test_resend_for_email_does_not_reveal_whether_the_account_exists(): void
    {
        $response = $this->postJson('/api/auth/email/resend', ['email' => 'missing@example.com']);

        $response->assertOk();
    }
}
