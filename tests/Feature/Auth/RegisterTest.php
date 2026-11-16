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
            'userType' => 'seller_buyer',
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '555-123-4567',
            'streetAddress' => '123 Main St',
            'city' => 'Metropolis',
            'state' => 'NY',
            'zip' => '10001',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.email', 'jane@example.com');
        $response->assertJsonPath('data.user.role', UserRole::SellerBuyer->value);
        $response->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'role' => UserRole::SellerBuyer->value,
        ]);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'userType' => 'seller_buyer',
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '555-123-4567',
            'streetAddress' => '123 Main St',
            'city' => 'Metropolis',
            'state' => 'NY',
            'zip' => '10001',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    }

    public function test_registration_fails_when_passwords_do_not_match(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'userType' => 'seller_buyer',
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '555-123-4567',
            'streetAddress' => '123 Main St',
            'city' => 'Metropolis',
            'state' => 'NY',
            'zip' => '10001',
            'password' => 'password',
            'passwordConfirmation' => 'different-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('password');
    }

    public function test_user_can_register_as_client(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'userType' => 'client',
            'firstName' => 'Alex',
            'lastName' => 'Client',
            'email' => 'alex@example.com',
            'phone' => '555-987-6543',
            'streetAddress' => '456 Oak St',
            'city' => 'Detroit',
            'state' => 'MI',
            'zip' => '48201',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.user.email', 'alex@example.com');
        $response->assertJsonPath('data.user.role', UserRole::SellerBuyer->value);

        $this->assertDatabaseHas('users', [
            'email' => 'alex@example.com',
            'role' => UserRole::SellerBuyer->value,
        ]);

        $user = User::where('email', 'alex@example.com')->first();
        $this->assertTrue($user->isClient());
        $this->assertTrue($user->isSeller());
        $this->assertTrue($user->isBuyer());
        $this->assertTrue($user->isSellerBuyer());
    }
}
