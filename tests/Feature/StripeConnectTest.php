<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Agents' Stripe Connect Express payout accounts: onboarding, status and the Connect webhook.
 */
class StripeConnectTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECT_SECRET = 'whsec_connect_test';

    protected FakeStripeGateway $stripe;
    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.connect_webhook_secret' => self::CONNECT_SECRET,
        ]);
        $this->stripe = new FakeStripeGateway();
        $this->app->instance(StripeGateway::class, $this->stripe);

        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
    }

    private function connectWebhook(array $event, string $secret = self::CONNECT_SECRET)
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return $this->call('POST', '/api/stripe/connect-webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function accountUpdated(string $accountId, array $state): array
    {
        return [
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => 'account.updated',
            'account' => $accountId,
            'data' => ['object' => array_merge([
                'id' => $accountId,
                'object' => 'account',
                'details_submitted' => true,
                'charges_enabled' => true,
                'payouts_enabled' => true,
                'requirements' => ['disabled_reason' => null, 'currently_due' => [], 'past_due' => []],
            ], $state)],
        ];
    }

    public function test_status_starts_as_not_started(): void
    {
        $this->actingAs($this->agent)->getJson('/api/agent/payout-account')
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started')
            ->assertJsonPath('data.available', true);
    }

    public function test_onboarding_creates_one_us_express_account_with_manual_payouts(): void
    {
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://connect.stripe.test/setup/acct_test_1');

        // Second click reuses the same account
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertOk();
        $this->assertCount(1, $this->stripe->accounts);

        $params = $this->stripe->accounts['acct_test_1'];
        $this->assertSame('express', $params['type']);
        $this->assertSame('US', $params['country']);
        $this->assertSame('manual', $params['settings']['payouts']['schedule']['interval']);
        $this->assertSame((string) $this->agent->id, $params['metadata']['user_id']);

        $this->assertSame('acct_test_1', $this->agent->fresh()->stripe_account_id);
        $this->actingAs($this->agent)->getJson('/api/agent/payout-account')->assertJsonPath('data.status', 'pending');
    }

    public function test_sellers_cannot_set_up_payouts(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);

        $this->actingAs($seller)->postJson('/api/agent/payout-account/onboarding')->assertForbidden();
        $this->assertCount(0, $this->stripe->accounts);
    }

    public function test_webhook_marks_the_account_ready_and_restricted(): void
    {
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertOk();

        $this->connectWebhook($this->accountUpdated('acct_test_1', []))->assertOk();
        $this->assertSame('ready', $this->agent->fresh()->payoutStatus());
        $this->assertTrue($this->agent->fresh()->canReceivePayments());

        $this->connectWebhook($this->accountUpdated('acct_test_1', [
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'requirements' => ['disabled_reason' => 'rejected.fraud', 'currently_due' => [], 'past_due' => []],
        ]))->assertOk();
        $this->assertSame('restricted', $this->agent->fresh()->payoutStatus());
        $this->assertFalse($this->agent->fresh()->canReceivePayments());
    }

    public function test_verification_in_progress_is_pending_not_restricted(): void
    {
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertOk();

        $this->connectWebhook($this->accountUpdated('acct_test_1', [
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'requirements' => ['disabled_reason' => 'requirements.pending_verification', 'currently_due' => [], 'past_due' => []],
        ]))->assertOk();

        $this->assertSame('pending', $this->agent->fresh()->payoutStatus());
    }

    public function test_webhook_rejects_a_bad_signature_and_ignores_unknown_accounts(): void
    {
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertOk();

        $this->connectWebhook($this->accountUpdated('acct_test_1', []), 'whsec_wrong')->assertStatus(400);
        $this->assertSame('pending', $this->agent->fresh()->payoutStatus());

        $this->connectWebhook($this->accountUpdated('acct_someone_else', []))->assertOk();
        $this->assertSame('pending', $this->agent->fresh()->payoutStatus());
    }

    public function test_returning_from_onboarding_refreshes_the_status_from_stripe(): void
    {
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertOk();
        $this->stripe->accountStates['acct_test_1'] = [
            'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true,
            'disabled_reason' => null, 'requirements_due' => 0,
        ];

        $this->actingAs($this->agent)->getJson('/api/agent/payout-account?refresh=1')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_dashboard_link_only_after_onboarding_is_submitted(): void
    {
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/dashboard')->assertStatus(422);

        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertOk();
        $this->connectWebhook($this->accountUpdated('acct_test_1', []))->assertOk();

        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/dashboard')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://connect.stripe.test/express/acct_test_1');
    }

    public function test_deauthorized_account_can_no_longer_receive_payments(): void
    {
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertOk();
        $this->connectWebhook($this->accountUpdated('acct_test_1', []))->assertOk();

        $this->connectWebhook([
            'id' => 'evt_deauth', 'object' => 'event', 'type' => 'account.application.deauthorized',
            'account' => 'acct_test_1', 'data' => ['object' => ['id' => 'ca_1', 'object' => 'application']],
        ])->assertOk();

        $this->assertFalse($this->agent->fresh()->canReceivePayments());
    }

    public function test_payout_endpoints_are_refused_when_stripe_is_not_configured(): void
    {
        config(['services.stripe.secret' => null]);

        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertStatus(503);
        $this->assertCount(0, $this->stripe->accounts);
    }

    public function test_account_id_is_not_exposed_in_user_json(): void
    {
        $this->actingAs($this->agent)->postJson('/api/agent/payout-account/onboarding')->assertOk();

        $this->assertArrayNotHasKey('stripe_account_id', $this->agent->fresh()->toArray());
    }
}
