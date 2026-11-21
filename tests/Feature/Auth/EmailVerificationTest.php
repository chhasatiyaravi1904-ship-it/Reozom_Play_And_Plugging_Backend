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

    public function test_registration_sends_the_verification_link_to_sellers_and_agents(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        foreach (['seller_buyer' => 'sam@example.com', 'agent' => 'ava@example.com'] as $type => $email) {
            $this->postJson('/api/auth/register', [
                'userType' => $type, 'firstName' => 'New', 'lastName' => 'User', 'email' => $email,
                'phone' => '5555550100', 'streetAddress' => '1 Main St', 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
                'password' => 'Password123!', 'passwordConfirmation' => 'Password123!',
            ])->assertCreated();

            \Illuminate\Support\Facades\Notification::assertSentTo(
                User::where('email', $email)->first(),
                \App\Notifications\VerifyEmailNotification::class
            );
        }
    }

    public function test_resend_sends_a_new_verification_link_to_every_role(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $seller = User::factory()->unverified()->create(['role' => 'seller_buyer']);
        $agent = User::factory()->unverified()->create(['role' => 'agent']);

        // From the login page (no session) and from the Verify email page (logged in)
        $this->postJson('/api/auth/email/resend', ['email' => $agent->email])->assertOk();
        $this->actingAs($seller)->postJson('/api/auth/email/verification-notification')->assertOk();

        foreach ([$seller, $agent] as $user) {
            \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\VerifyEmailNotification::class);
        }
        // Agents no longer get the "we're reviewing your application" notice instead of a link
        \Illuminate\Support\Facades\Notification::assertNotSentTo($agent, \App\Notifications\AgentRegisteredNotification::class);
    }

    public function test_an_agent_can_verify_with_the_link(): void
    {
        $agent = User::factory()->unverified()->create(['role' => 'agent']);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $agent->id,
            'hash' => sha1($agent->getEmailForVerification()),
        ]);

        $this->get($url)->assertRedirect();
        $this->assertTrue($agent->fresh()->hasVerifiedEmail());
    }
}
