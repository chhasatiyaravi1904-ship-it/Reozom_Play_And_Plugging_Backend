<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'fullName' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '555-123-4567',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.email', 'jane@example.com');
        // Public registration always creates a seller — agent/admin accounts
        // are provisioned separately, not through open registration.
        $response->assertJsonPath('data.user.role', UserRole::Seller->value);
        $response->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'role' => UserRole::Seller->value,
        ]);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'fullName' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '555-123-4567',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    }

    public function test_registration_fails_when_passwords_do_not_match(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'fullName' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '555-123-4567',
            'password' => 'password',
            'passwordConfirmation' => 'different-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('password');
    }
}
