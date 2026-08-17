<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_login_records_a_login_log_entry(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('user_login_logs', [
            'user_id' => $user->id,
            'login_type' => 'direct',
        ]);
    }

    public function test_admin_login_also_records_an_admin_activity_log_entry(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $admin->id,
            'action' => 'login',
        ]);
    }

    public function test_seller_login_does_not_record_an_admin_activity_log_entry(): void
    {
        $seller = User::factory()->create(['role' => UserRole::Seller, 'email' => 'seller@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => $seller->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseMissing('admin_activity_logs', ['user_id' => $seller->id]);
    }
}
