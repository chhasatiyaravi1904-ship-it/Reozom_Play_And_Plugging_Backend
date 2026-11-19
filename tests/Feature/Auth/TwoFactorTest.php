<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function loginWithPassword(User $user): void
    {
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.requires_2fa', true);
    }

    public function test_verify_without_password_login_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->postJson('/api/auth/verify-2fa', ['email' => $admin->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonMissingPath('data.token');
    }

    public function test_verify_for_unknown_email_is_rejected(): void
    {
        $this->postJson('/api/auth/verify-2fa', ['email' => 'nobody@example.com', 'code' => '123456'])
            ->assertStatus(422);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->loginWithPassword($user);

        $this->postJson('/api/auth/verify-2fa', ['email' => $user->email, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->loginWithPassword($user);
        $user->forceFill(['two_factor_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/auth/verify-2fa', ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_correct_code_logs_in_once(): void
    {
        $user = User::factory()->create();
        $this->loginWithPassword($user);

        $this->postJson('/api/auth/verify-2fa', ['email' => $user->email, 'code' => '123456'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user']]);

        // The code is single-use.
        $this->postJson('/api/auth/verify-2fa', ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(422);
    }

    public function test_code_is_blocked_after_five_wrong_attempts_until_password_login(): void
    {
        $user = User::factory()->create();
        $this->loginWithPassword($user);

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/verify-2fa', ['email' => $user->email, 'code' => '00000' . $i])
                ->assertStatus(422);
        }

        // Even the right code no longer works...
        $this->postJson('/api/auth/verify-2fa', ['email' => $user->email, 'code' => '123456'])
            ->assertStatus(429);

        // ...until the password is entered again.
        $this->loginWithPassword($user);
        $this->postJson('/api/auth/verify-2fa', ['email' => $user->email, 'code' => '123456'])
            ->assertOk();
    }

    public function test_resend_does_nothing_without_a_pending_login(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/resend-2fa', ['email' => $user->email])->assertOk();

        $this->assertNull($user->fresh()->two_factor_code);
        Mail::assertNothingSent();
    }
}
