<?php

namespace Tests\Feature;

use App\Models\AgentBankAccount;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\ServicePackage;
use App\Models\User;
use App\Notifications\WithdrawalProcessedNotification;
use App\Notifications\WithdrawalRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sellers pay the platform; the agent requests their share and an admin pays it by bank
 * transfer, then marks it paid (with the reference) or rejects it.
 */
class ManualWithdrawalsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private User $admin;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->agent = User::factory()->create(['role' => 'agent', 'first_name' => 'Ava']);
        $this->seller = User::factory()->create(['role' => 'seller_buyer']);

        $role = Role::findOrCreate('payouts-manager-test');
        $role->givePermissionTo(Permission::findOrCreate('manage-packages'));
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->admin->assignRole($role);
    }

    /** A paid seller payment giving the agent $share (dollars). */
    private function earn(float $share, string $status = Payment::STATUS_PAID, ?string $destination = null): Payment
    {
        $package = ServicePackage::factory()->create(['agent_id' => $this->agent->id]);
        $listing = Listing::factory()->create(['user_id' => $this->seller->id, 'service_package_id' => $package->id]);

        return Payment::create([
            'type' => Payment::TYPE_LISTING, 'user_id' => $this->seller->id, 'listing_id' => $listing->id,
            'service_package_id' => $package->id, 'agent_id' => $this->agent->id,
            'amount' => (int) round($share * 100) + 1000, 'currency' => 'usd', 'agent_amount' => (int) round($share * 100),
            'destination_account_id' => $destination, 'status' => $status,
            'stripe_session_id' => 'cs_'.uniqid(), 'paid_at' => now(),
        ]);
    }

    private function bankDetails(array $overrides = []): array
    {
        return array_merge([
            'account_holder_name' => 'Ava Agent', 'bank_name' => 'Chase', 'routing_number' => '021000021',
            'account_number' => '000123456789', 'account_number_confirmation' => '000123456789', 'account_type' => 'checking',
        ], $overrides);
    }

    private function saveBank(array $overrides = [])
    {
        return $this->actingAs($this->agent)->putJson('/api/agent/bank-account', $this->bankDetails($overrides));
    }

    private function overview()
    {
        return $this->actingAs($this->agent)->getJson('/api/agent/payouts')->assertOk();
    }

    // --- Bank details ---

    public function test_agent_saves_bank_details_encrypted_and_sees_only_the_last_digits(): void
    {
        $this->saveBank()->assertOk()
            ->assertJsonPath('data.account_last4', '6789')
            ->assertJsonPath('data.routing_last4', '0021')
            ->assertJsonMissingPath('data.account_number');

        // Stored encrypted, not as plain digits
        $raw = DB::table('agent_bank_accounts')->where('agent_id', $this->agent->id)->first();
        $this->assertStringNotContainsString('000123456789', $raw->account_number);
        $this->assertSame('000123456789', AgentBankAccount::first()->account_number);

        $this->overview()->assertJsonPath('data.bank_account.bank_name', 'Chase')
            ->assertJsonMissingPath('data.bank_account.account_number');

        // Saving again replaces the account (still one per agent)
        $this->saveBank(['account_number' => '99998888', 'account_number_confirmation' => '99998888'])->assertOk();
        $this->assertSame(1, AgentBankAccount::count());
        $this->assertSame('8888', AgentBankAccount::first()->account_last4);
    }

    public function test_bank_details_are_validated(): void
    {
        $this->saveBank(['routing_number' => '12345'])->assertStatus(422)->assertJsonValidationErrors('routing_number');
        $this->saveBank(['account_number_confirmation' => '111'])->assertStatus(422)->assertJsonValidationErrors('account_number');
        $this->saveBank(['account_number' => '12ab', 'account_number_confirmation' => '12ab'])->assertStatus(422);
        $this->saveBank(['account_type' => 'crypto'])->assertStatus(422)->assertJsonValidationErrors('account_type');
        $this->actingAs($this->seller)->putJson('/api/agent/bank-account', $this->bankDetails())->assertForbidden();
    }

    // --- Balance & requests ---

    public function test_balance_counts_paid_platform_payments_only(): void
    {
        $this->earn(200);
        $this->earn(50, Payment::STATUS_REFUNDED);            // refunded: not counted
        $this->earn(80, Payment::STATUS_PENDING);             // not paid yet
        $this->earn(300, Payment::STATUS_PAID, 'acct_old');   // old split payment: already with the agent

        $this->overview()->assertJsonPath('data.balance.available', 200)
            ->assertJsonPath('data.balance.pending', 0)
            ->assertJsonPath('data.balance.withdrawn', 0);
    }

    public function test_agent_requests_a_withdrawal_and_admins_are_emailed(): void
    {
        $this->earn(286.14);
        $this->saveBank();

        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => '200.00'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.method', 'manual')
            ->assertJsonPath('data.bank_last4', '6789');

        $this->overview()->assertJsonPath('data.balance.available', 86.14)->assertJsonPath('data.balance.pending', 200);

        $payout = Payout::first();
        $this->assertSame('021000021', $payout->bank_details['routing_number']);
        Notification::assertSentTo($this->admin, WithdrawalRequestedNotification::class);
    }

    public function test_withdrawal_rules(): void
    {
        $this->earn(50);

        // No bank details yet
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 10])->assertStatus(422);

        $this->saveBank();
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 0.5])->assertStatus(422);    // below $1
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 60])->assertStatus(422);     // more than available
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => '10.555'])->assertStatus(422); // 3 decimals
        $this->assertSame(0, Payout::count());

        // (the endpoint allows 5 requests a minute; start a fresh minute)
        \Illuminate\Support\Facades\Cache::flush();

        // requests reserve the balance, so a second one can't over-draw
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 40])->assertCreated();
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 20])->assertStatus(422);

        $this->actingAs($this->seller)->postJson('/api/agent/payouts', ['amount' => 10])->assertForbidden();
    }

    public function test_the_request_keeps_the_bank_details_it_was_made_with(): void
    {
        $this->earn(100);
        $this->saveBank();
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 100])->assertCreated();

        $this->saveBank(['account_number' => '55554444', 'account_number_confirmation' => '55554444']);

        $this->assertSame('000123456789', Payout::first()->bank_details['account_number']);
    }

    public function test_agent_can_cancel_only_their_own_pending_request(): void
    {
        $this->earn(100);
        $this->saveBank();
        $id = $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 100])->json('data.id');

        $other = User::factory()->create(['role' => 'agent']);
        $this->actingAs($other)->postJson("/api/agent/payouts/{$id}/cancel")->assertNotFound();

        $this->actingAs($this->agent)->postJson("/api/agent/payouts/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->overview()->assertJsonPath('data.balance.available', 100);

        // Already canceled
        $this->actingAs($this->agent)->postJson("/api/agent/payouts/{$id}/cancel")->assertStatus(422);
    }

    // --- Admin ---

    public function test_admin_sees_full_bank_details_for_pending_requests_and_marks_them_paid(): void
    {
        $this->earn(150);
        $this->saveBank();
        $id = $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 150])->json('data.id');

        $this->actingAs($this->admin)->getJson('/api/admin/payouts')->assertOk()
            ->assertJsonPath('data.items.0.bank_details.account_number', '000123456789')
            ->assertJsonPath('data.items.0.bank_details.routing_number', '021000021')
            ->assertJsonPath('data.pending_total', 150);

        $this->actingAs($this->admin)->postJson("/api/admin/payouts/{$id}/mark-paid", [])->assertStatus(422);  // reference required
        $this->actingAs($this->admin)->postJson("/api/admin/payouts/{$id}/mark-paid", ['reference' => 'WIRE-7781'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.reference', 'WIRE-7781');

        Notification::assertSentTo($this->agent, WithdrawalProcessedNotification::class);
        $this->overview()->assertJsonPath('data.balance.withdrawn', 150)->assertJsonPath('data.balance.available', 0);

        // Once paid, the admin list masks the numbers and the request can't change again
        $this->actingAs($this->admin)->getJson('/api/admin/payouts')
            ->assertJsonPath('data.items.0.bank_details.account_number', '••••6789');
        $this->actingAs($this->admin)->postJson("/api/admin/payouts/{$id}/reject", ['reason' => 'x'])->assertStatus(422);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'payout_marked_paid']);
    }

    public function test_admin_rejects_a_request_and_the_money_returns_to_the_balance(): void
    {
        $this->earn(90);
        $this->saveBank();
        $id = $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 90])->json('data.id');

        $this->actingAs($this->admin)->postJson("/api/admin/payouts/{$id}/reject", [])->assertStatus(422); // reason required
        $this->actingAs($this->admin)->postJson("/api/admin/payouts/{$id}/reject", ['reason' => 'Bank account name does not match'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.failure_message', 'Bank account name does not match');

        Notification::assertSentTo($this->agent, WithdrawalProcessedNotification::class);
        $this->overview()->assertJsonPath('data.balance.available', 90);
    }

    public function test_only_admins_with_permission_can_process_requests(): void
    {
        $this->earn(50);
        $this->saveBank();
        $id = $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 50])->json('data.id');

        $this->actingAs($this->agent)->postJson("/api/admin/payouts/{$id}/mark-paid", ['reference' => 'x'])->assertForbidden();
        $this->actingAs($this->agent)->getJson('/api/admin/payouts')->assertForbidden();

        $plainAdmin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($plainAdmin)->postJson("/api/admin/payouts/{$id}/mark-paid", ['reference' => 'x'])->assertForbidden();
        $this->assertSame('pending', Payout::find($id)->status);
    }
}
