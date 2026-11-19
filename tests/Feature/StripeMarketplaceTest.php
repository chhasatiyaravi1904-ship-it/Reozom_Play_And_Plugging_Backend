<?php

namespace Tests\Feature;

use App\Models\AgentPackage;
use App\Models\Listing;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PlatformSetting;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Phases 3-5: seller payments split to the agent's Connect account (agent pays the platform fee
 * and Stripe's fee), agent withdrawals, refunds and the admin views.
 */
class StripeMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_platform';
    private const CONNECT_SECRET = 'whsec_connect';

    protected FakeStripeGateway $stripe;
    protected User $agent;
    protected User $seller;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
            'services.stripe.currency' => 'usd',
        ]);
        $this->stripe = new FakeStripeGateway();
        $this->app->instance(StripeGateway::class, $this->stripe);

        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->seller = User::factory()->create(['role' => 'seller', 'is_active' => true]);

        $role = Role::findOrCreate('payments-manager-test');
        $role->givePermissionTo(Permission::findOrCreate('manage-packages'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole($role);
    }

    private function makeAgentReady(): void
    {
        $this->agent->forceFill([
            'stripe_account_id' => 'acct_agent_1',
            'stripe_details_submitted' => true,
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
        ])->save();
    }

    private function listing(float $price = 295.00): Listing
    {
        $package = ServicePackage::create(['agent_id' => $this->agent->id, 'name' => 'Full MLS', 'price' => $price, 'is_active' => true]);

        return Listing::factory()->create(['user_id' => $this->seller->id, 'service_package_id' => $package->id, 'status' => 'in_progress']);
    }

    private function signed(string $url, array $event, string $secret)
    {
        $payload = json_encode($event);
        $t = time();

        return $this->call('POST', $url, [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function payCheckout(string $sessionId, int $amount, string $intent = 'pi_1')
    {
        return $this->signed('/api/stripe/webhook', [
            'id' => 'evt_'.uniqid(), 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => $sessionId, 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
                'amount_total' => $amount, 'currency' => 'usd', 'payment_intent' => $intent,
            ]],
        ], self::WEBHOOK_SECRET);
    }

    // --- Phase 3: split payments ---

    public function test_seller_cannot_pay_until_the_agent_can_receive_payments(): void
    {
        $listing = $this->listing();

        $this->actingAs($this->seller)->getJson("/api/listings/{$listing->id}/payment-status")
            ->assertJsonPath('data.agent_ready', false);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertStatus(422);
        $this->assertSame(0, Payment::count());
    }

    public function test_with_zero_platform_fee_the_agent_pays_only_the_stripe_fee(): void
    {
        $this->makeAgentReady();
        $listing = $this->listing(295.00);

        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();

        // Stripe fee 2.9% + 30c of $295.00 = 855.5 -> 856 + 30 = 886 cents
        $params = $this->stripe->createdSessions[0];
        $this->assertSame(886, $params['payment_intent_data']['application_fee_amount']);
        $this->assertSame('acct_agent_1', $params['payment_intent_data']['transfer_data']['destination']);

        $this->assertDatabaseHas('payments', [
            'type' => 'listing', 'amount' => 29500, 'platform_fee' => 0, 'processing_fee' => 886,
            'agent_amount' => 28614, 'destination_account_id' => 'acct_agent_1',
        ]);
    }

    public function test_admin_platform_fee_is_added_to_what_the_agent_pays(): void
    {
        $this->makeAgentReady();
        $this->actingAs($this->admin)->putJson('/api/admin/payment-settings', ['platform_fee_percent' => 10, 'platform_fee_fixed' => 5])
            ->assertOk()
            ->assertJsonPath('data.platform_fee_percent', 10)
            ->assertJsonPath('data.example.platform_fee', 34.5);

        $listing = $this->listing(295.00);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();

        // 10% of 29500 = 2950 + 500 fixed = 3450; Stripe 886; agent 29500 - 3450 - 886 = 25164
        $this->assertSame(3450 + 886, $this->stripe->createdSessions[0]['payment_intent_data']['application_fee_amount']);
        $this->assertDatabaseHas('payments', ['platform_fee' => 3450, 'processing_fee' => 886, 'agent_amount' => 25164]);
    }

    public function test_agent_package_payments_are_not_split(): void
    {
        $package = Package::create(['name' => 'Pro', 'slug' => 'pro', 'role_name' => 'pro', 'price' => 49.99, 'duration_days' => 30, 'is_active' => true]);

        $this->actingAs($this->agent)->postJson("/api/packages/{$package->id}/checkout")->assertOk();

        $this->assertArrayNotHasKey('payment_intent_data', $this->stripe->createdSessions[0]);
        $this->assertDatabaseHas('payments', ['type' => 'agent_package', 'platform_fee' => 0, 'agent_amount' => 0, 'destination_account_id' => null]);
    }

    public function test_payment_settings_are_validated_and_admin_only(): void
    {
        $this->actingAs($this->admin)->putJson('/api/admin/payment-settings', ['platform_fee_percent' => 80])->assertStatus(422);
        $this->actingAs($this->admin)->putJson('/api/admin/payment-settings', ['platform_fee_percent' => -1])->assertStatus(422);
        $this->actingAs($this->agent)->putJson('/api/admin/payment-settings', ['platform_fee_percent' => 5])->assertForbidden();
        $this->assertSame(0.0, (float) PlatformSetting::getValue(PlatformSetting::PLATFORM_FEE_PERCENT, 0));
    }

    public function test_price_too_low_to_cover_fees_is_refused(): void
    {
        $this->makeAgentReady();
        $listing = $this->listing(0.50); // Stripe fee alone is 31c... plus platform fee below

        PlatformSetting::setValue(PlatformSetting::PLATFORM_FEE_FIXED_CENTS, 100);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertStatus(422);
    }

    // --- Phase 4: withdrawals ---

    public function test_agent_sees_balance_earnings_and_can_withdraw(): void
    {
        $this->makeAgentReady();
        $listing = $this->listing(295.00);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();
        $this->payCheckout('cs_test_1', 29500)->assertOk();

        $this->stripe->balances['acct_agent_1'] = ['available' => 28614, 'pending' => 0];

        $this->actingAs($this->agent)->getJson('/api/agent/payouts')
            ->assertOk()
            ->assertJsonPath('data.balance.available', 286.14)
            ->assertJsonPath('data.earnings.sales', 1)
            ->assertJsonPath('data.earnings.net', 286.14)
            ->assertJsonPath('data.account.status', 'ready');

        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => '200.00'])
            ->assertCreated()
            ->assertJsonPath('data.amount', 200)
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame([['accountId' => 'acct_agent_1', 'amount' => 20000, 'currency' => 'usd']], array_map(
            fn ($p) => array_intersect_key($p, array_flip(['accountId', 'amount', 'currency'])),
            $this->stripe->payoutsCreated
        ));
        $this->assertDatabaseHas('payouts', ['agent_id' => $this->agent->id, 'amount' => 20000, 'stripe_payout_id' => 'po_test_1']);
    }

    public function test_withdrawal_rules(): void
    {
        // Not set up yet
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 10])->assertStatus(422);

        $this->makeAgentReady();
        $this->stripe->balances['acct_agent_1'] = ['available' => 5000, 'pending' => 9000];

        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 0.5])->assertStatus(422);   // below $1
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 60])->assertStatus(422);    // more than available
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => '10.555'])->assertStatus(422); // 3 decimals
        $this->assertSame([], $this->stripe->payoutsCreated);

        $this->actingAs($this->seller)->postJson('/api/agent/payouts', ['amount' => 10])->assertForbidden();
    }

    public function test_payout_webhook_updates_status_only_for_the_matching_account(): void
    {
        $this->makeAgentReady();
        $this->stripe->balances['acct_agent_1'] = ['available' => 5000, 'pending' => 0];
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 50])->assertCreated();

        $event = fn (string $type, string $status, string $account, array $extra = []) => [
            'id' => 'evt_'.uniqid(), 'object' => 'event', 'type' => $type, 'account' => $account,
            'data' => ['object' => array_merge(['id' => 'po_test_1', 'object' => 'payout', 'status' => $status, 'arrival_date' => time()], $extra)],
        ];

        // Another account can't change this payout
        $this->signed('/api/stripe/connect-webhook', $event('payout.paid', 'paid', 'acct_other'), self::CONNECT_SECRET)->assertOk();
        $this->assertSame('pending', Payout::first()->status);

        $this->signed('/api/stripe/connect-webhook', $event('payout.paid', 'paid', 'acct_agent_1'), self::CONNECT_SECRET)->assertOk();
        $this->assertSame('paid', Payout::first()->status);
        $this->assertNotNull(Payout::first()->paid_at);

        // Forged signature is rejected
        $this->signed('/api/stripe/connect-webhook', $event('payout.failed', 'failed', 'acct_agent_1'), 'whsec_wrong')->assertStatus(400);
        $this->assertSame('paid', Payout::first()->status);
    }

    public function test_failed_payout_records_the_reason(): void
    {
        $this->makeAgentReady();
        $this->stripe->balances['acct_agent_1'] = ['available' => 5000, 'pending' => 0];
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 50])->assertCreated();

        $this->signed('/api/stripe/connect-webhook', [
            'id' => 'evt_f', 'object' => 'event', 'type' => 'payout.failed', 'account' => 'acct_agent_1',
            'data' => ['object' => ['id' => 'po_test_1', 'object' => 'payout', 'status' => 'failed', 'failure_message' => 'The bank account has been closed.']],
        ], self::CONNECT_SECRET)->assertOk();

        $this->assertSame('failed', Payout::first()->status);
        $this->assertSame('The bank account has been closed.', Payout::first()->failure_message);
    }

    // --- Phase 5: refunds & admin views ---

    public function test_admin_refund_of_a_seller_payment_reverses_the_agent_transfer(): void
    {
        $this->makeAgentReady();
        $listing = $this->listing(295.00);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();
        $this->payCheckout('cs_test_1', 29500, 'pi_listing')->assertOk();
        $payment = Payment::first();

        $this->actingAs($this->agent)->postJson("/api/admin/payments/{$payment->id}/refund")->assertForbidden();

        $this->actingAs($this->admin)->postJson("/api/admin/payments/{$payment->id}/refund")->assertOk();
        $this->assertSame([['payment_intent' => 'pi_listing', 'reverse_transfer' => true]], $this->stripe->refunds);

        // A second click doesn't refund twice
        $this->actingAs($this->admin)->postJson("/api/admin/payments/{$payment->id}/refund")->assertStatus(422);

        $this->signed('/api/stripe/webhook', [
            'id' => 'evt_r', 'object' => 'event', 'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge', 'refunded' => true, 'payment_intent' => 'pi_listing']],
        ], self::WEBHOOK_SECRET)->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
    }

    public function test_refunded_agent_package_stops_access(): void
    {
        $package = Package::create(['name' => 'Pro', 'slug' => 'pro', 'role_name' => 'pro', 'price' => 49.99, 'duration_days' => 30, 'is_active' => true]);
        $this->actingAs($this->agent)->postJson("/api/packages/{$package->id}/checkout")->assertOk();
        $this->payCheckout('cs_test_1', 4999, 'pi_pkg')->assertOk();
        $this->assertTrue($this->agent->fresh()->hasActivePackage());

        $payment = Payment::first();
        $this->actingAs($this->admin)->postJson("/api/admin/payments/{$payment->id}/refund")->assertOk();
        $this->assertSame([['payment_intent' => 'pi_pkg', 'reverse_transfer' => false]], $this->stripe->refunds);

        $this->signed('/api/stripe/webhook', [
            'id' => 'evt_r', 'object' => 'event', 'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge', 'refunded' => true, 'payment_intent' => 'pi_pkg']],
        ], self::WEBHOOK_SECRET)->assertOk();

        $this->assertFalse($this->agent->fresh()->hasActivePackage());
        $this->assertSame(1, AgentPackage::count());
    }

    public function test_unpaid_payments_cannot_be_refunded(): void
    {
        $this->makeAgentReady();
        $listing = $this->listing();
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();

        $this->actingAs($this->admin)->postJson('/api/admin/payments/'.Payment::first()->id.'/refund')->assertStatus(422);
        $this->assertSame([], $this->stripe->refunds);
    }

    public function test_admin_sees_fees_agent_accounts_and_withdrawals(): void
    {
        $this->makeAgentReady();
        $listing = $this->listing(295.00);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();
        $this->payCheckout('cs_test_1', 29500)->assertOk();
        $this->stripe->balances['acct_agent_1'] = ['available' => 10000, 'pending' => 0];
        $this->actingAs($this->agent)->postJson('/api/agent/payouts', ['amount' => 50])->assertCreated();

        $this->actingAs($this->admin)->getJson('/api/admin/payments?type=listing')
            ->assertOk()
            ->assertJsonPath('data.items.0.processing_fee', 8.86)
            ->assertJsonPath('data.items.0.agent_amount', 286.14)
            ->assertJsonPath('data.totals.paid.agent_amount', 286.14);

        $this->actingAs($this->admin)->getJson('/api/admin/payout-accounts')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->agent->id, 'payout_status' => 'ready', 'net' => 286.14]);

        $this->actingAs($this->admin)->getJson('/api/admin/payouts')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.agent.id', $this->agent->id);

        $this->actingAs($this->agent)->getJson('/api/admin/payouts')->assertForbidden();
    }
}
