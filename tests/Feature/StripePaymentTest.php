<?php

namespace Tests\Feature;

use App\Models\AgentPackage;
use App\Models\Listing;
use App\Models\Package;
use App\Models\Payment;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Stripe Checkout for agent packages and listing payments. Stripe API calls are faked;
 * webhook payloads are signed exactly like Stripe signs them.
 */
class StripePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected FakeStripeGateway $stripe;
    protected User $agent;
    protected User $seller;
    protected Package $paidPackage;
    protected Package $freePackage;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
            'services.stripe.currency' => 'usd',
        ]);

        $this->stripe = new FakeStripeGateway();
        $this->app->instance(StripeGateway::class, $this->stripe);

        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->seller = User::factory()->create(['role' => 'seller', 'is_active' => true]);

        $this->paidPackage = Package::create([
            'name' => 'Full Access', 'slug' => 'full-access', 'role_name' => 'full-access',
            'price' => 49.99, 'duration_days' => 30, 'is_active' => true,
        ]);
        $this->freePackage = Package::create([
            'name' => 'Starter', 'slug' => 'starter', 'role_name' => 'starter',
            'price' => null, 'duration_days' => 30, 'is_active' => true,
        ]);
    }

    private function listingWithPackage(?float $price = 295.00, bool $agentReady = true): Listing
    {
        if ($agentReady) {
            // Seller payments go to the agent's Stripe Connect account, so it must be ready
            $this->agent->forceFill([
                'stripe_account_id' => 'acct_agent_1',
                'stripe_details_submitted' => true,
                'stripe_charges_enabled' => true,
                'stripe_payouts_enabled' => true,
            ])->save();
        }

        $servicePackage = ServicePackage::create([
            'agent_id' => $this->agent->id, 'name' => 'Full MLS', 'price' => $price, 'is_active' => true,
        ]);

        return Listing::factory()->create([
            'user_id' => $this->seller->id,
            'service_package_id' => $servicePackage->id,
            'status' => 'in_progress',
        ]);
    }

    private function signedWebhook(array $event)
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function sessionEvent(string $type, string $sessionId, array $overrides = []): array
    {
        return [
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge([
                'id' => $sessionId,
                'object' => 'checkout.session',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => 4999,
                'currency' => 'usd',
                'payment_intent' => 'pi_123',
            ], $overrides)],
        ];
    }

    // --- Agent package ---

    public function test_paid_package_checkout_uses_the_database_price(): void
    {
        $this->actingAs($this->agent)
            ->postJson("/api/packages/{$this->paidPackage->id}/checkout", ['amount' => 1, 'price' => 0.01])
            ->assertOk()
            ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/cs_test_1');

        $params = $this->stripe->createdSessions[0];
        // Charged through the plan's Stripe Price, which carries the database amount
        $priceId = $params['line_items'][0]['price'];
        $this->assertSame($this->paidPackage->fresh()->stripe_price_id, $priceId);
        $this->assertSame(4999, $this->stripe->prices[$priceId]['unit_amount']);
        $this->assertSame('usd', $this->stripe->prices[$priceId]['currency']);
        $this->assertSame('payment', $params['mode']);

        $this->assertDatabaseHas('payments', [
            'type' => Payment::TYPE_AGENT_PACKAGE, 'user_id' => $this->agent->id,
            'package_id' => $this->paidPackage->id, 'amount' => 4999, 'status' => 'pending',
        ]);
        $this->assertSame(0, AgentPackage::count(), 'Nothing is activated before Stripe confirms payment.');
    }

    public function test_paid_package_cannot_be_selected_for_free(): void
    {
        $this->actingAs($this->agent)
            ->postJson("/api/packages/{$this->paidPackage->id}/select")
            ->assertStatus(402);

        $this->assertSame(0, AgentPackage::count());
    }

    public function test_free_package_is_still_selected_directly(): void
    {
        $this->actingAs($this->agent)
            ->postJson("/api/packages/{$this->freePackage->id}/select")
            ->assertOk();

        $this->assertSame(1, AgentPackage::where('package_id', $this->freePackage->id)->count());
        $this->actingAs($this->agent)
            ->postJson("/api/packages/{$this->freePackage->id}/checkout")
            ->assertStatus(422);
    }

    public function test_return_page_activates_only_after_stripe_says_paid_and_only_once(): void
    {
        $this->actingAs($this->agent)->postJson("/api/packages/{$this->paidPackage->id}/checkout")->assertOk();

        // Stripe still says unpaid: nothing happens, whatever the browser claims.
        $this->stripe->sessions['cs_test_1'] = ['status' => 'open', 'payment_status' => 'unpaid'];
        $this->actingAs($this->agent)->getJson('/api/payments/session/cs_test_1')
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(0, AgentPackage::count());

        // Stripe says paid: activated once, even if the page is reloaded and the webhook arrives too.
        $this->stripe->sessions['cs_test_1'] = ['status' => 'complete', 'payment_status' => 'paid'];
        $this->actingAs($this->agent)->getJson('/api/payments/session/cs_test_1')
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->actingAs($this->agent)->getJson('/api/payments/session/cs_test_1')->assertOk();
        $this->signedWebhook($this->sessionEvent('checkout.session.completed', 'cs_test_1'))->assertOk();

        $this->assertSame(1, AgentPackage::where('package_id', $this->paidPackage->id)->count());
        $this->assertTrue($this->agent->fresh()->hasActivePackage());
        $this->assertNotNull(Payment::first()->agent_package_id);
    }

    public function test_another_user_cannot_read_or_confirm_someone_elses_payment(): void
    {
        $this->actingAs($this->agent)->postJson("/api/packages/{$this->paidPackage->id}/checkout")->assertOk();
        $this->stripe->sessions['cs_test_1'] = ['status' => 'complete', 'payment_status' => 'paid'];

        $other = User::factory()->create(['role' => 'agent']);
        $this->actingAs($other)->getJson('/api/payments/session/cs_test_1')->assertNotFound();
        $this->assertSame(0, AgentPackage::count());
    }

    // --- Webhook ---

    public function test_webhook_with_a_valid_signature_activates_the_package(): void
    {
        $this->actingAs($this->agent)->postJson("/api/packages/{$this->paidPackage->id}/checkout")->assertOk();

        $this->signedWebhook($this->sessionEvent('checkout.session.completed', 'cs_test_1'))->assertOk();

        $this->assertSame('paid', Payment::first()->status);
        $this->assertTrue($this->agent->fresh()->hasActivePackage());
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        $this->actingAs($this->agent)->postJson("/api/packages/{$this->paidPackage->id}/checkout")->assertOk();

        $payload = json_encode($this->sessionEvent('checkout.session.completed', 'cs_test_1'));
        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=forged',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(400);

        $this->assertSame('pending', Payment::first()->status);
        $this->assertSame(0, AgentPackage::count());
    }

    public function test_amount_mismatch_is_not_fulfilled(): void
    {
        $this->actingAs($this->agent)->postJson("/api/packages/{$this->paidPackage->id}/checkout")->assertOk();

        $this->signedWebhook($this->sessionEvent('checkout.session.completed', 'cs_test_1', ['amount_total' => 100]))->assertOk();

        $this->assertSame('failed', Payment::first()->status);
        $this->assertSame(0, AgentPackage::count());
    }

    public function test_expired_session_and_refund_are_recorded(): void
    {
        $this->actingAs($this->agent)->postJson("/api/packages/{$this->paidPackage->id}/checkout")->assertOk();
        $this->actingAs($this->agent)->postJson("/api/packages/{$this->paidPackage->id}/checkout")->assertOk();

        $this->signedWebhook($this->sessionEvent('checkout.session.expired', 'cs_test_1', ['status' => 'expired', 'payment_status' => 'unpaid']))->assertOk();
        $this->assertSame('expired', Payment::where('stripe_session_id', 'cs_test_1')->value('status'));

        $this->signedWebhook($this->sessionEvent('checkout.session.completed', 'cs_test_2', ['payment_intent' => 'pi_refund']))->assertOk();
        $this->signedWebhook([
            'id' => 'evt_refund', 'object' => 'event', 'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge', 'refunded' => true, 'payment_intent' => 'pi_refund']],
        ])->assertOk();
        $this->assertSame('refunded', Payment::where('stripe_session_id', 'cs_test_2')->value('status'));
    }

    // --- Listing payment ---

    public function test_listing_cannot_be_published_until_paid(): void
    {
        $listing = $this->listingWithPackage(295.00);

        $this->actingAs($this->seller)->getJson("/api/listings/{$listing->id}/payment-status")
            ->assertOk()
            ->assertJsonPath('data.required', true)
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('data.amount', 295);

        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/submit")->assertStatus(402);
        $this->assertNotSame('published', $listing->fresh()->status);

        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();
        $this->assertSame(29500, $this->stripe->prices[$this->stripe->createdSessions[0]['line_items'][0]['price']]['unit_amount']);
        $this->assertDatabaseHas('payments', [
            'type' => Payment::TYPE_LISTING, 'listing_id' => $listing->id, 'agent_id' => $this->agent->id, 'amount' => 29500,
        ]);

        $this->signedWebhook($this->sessionEvent('checkout.session.completed', 'cs_test_1', ['amount_total' => 29500]))->assertOk();

        $this->actingAs($this->seller)->getJson("/api/listings/{$listing->id}/payment-status")->assertJsonPath('data.paid', true);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/submit")->assertOk();
        $this->assertSame('published', $listing->fresh()->status);

        // Paying again is refused.
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertStatus(422);
    }

    public function test_confirmed_listing_payment_publishes_the_listing_once(): void
    {
        $listing = $this->listingWithPackage(295.00);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();

        // Not paid yet: still a draft
        $this->stripe->sessions['cs_test_1'] = ['status' => 'open', 'payment_status' => 'unpaid'];
        $this->actingAs($this->seller)->getJson('/api/payments/session/cs_test_1')->assertJsonPath('data.status', 'pending');
        $this->assertSame('in_progress', $listing->fresh()->status);

        // Return page confirms with Stripe: published, without clicking Submit
        $this->stripe->sessions['cs_test_1'] = ['status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 29500];
        $this->actingAs($this->seller)->getJson('/api/payments/session/cs_test_1')->assertJsonPath('data.status', 'paid');

        $published = $listing->fresh();
        $this->assertSame('published', $published->status);
        $this->assertSame('auto_approved', $published->moderation_status);
        $firstPublishedAt = $published->moderated_at;

        // A late webhook for the same session changes nothing
        $this->travel(5)->minutes();
        $this->signedWebhook($this->sessionEvent('checkout.session.completed', 'cs_test_1', ['amount_total' => 29500]))->assertOk();
        $this->assertEquals($firstPublishedAt, $listing->fresh()->moderated_at);
    }

    public function test_unconfirmed_or_mismatched_listing_payment_does_not_publish(): void
    {
        $listing = $this->listingWithPackage(295.00);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();

        $this->signedWebhook($this->sessionEvent('checkout.session.completed', 'cs_test_1', ['amount_total' => 100]))->assertOk();

        $this->assertSame('failed', Payment::first()->status);
        $this->assertSame('in_progress', $listing->fresh()->status);
    }

    public function test_free_service_package_needs_no_payment(): void
    {
        $listing = $this->listingWithPackage(null);

        $this->actingAs($this->seller)->getJson("/api/listings/{$listing->id}/payment-status")
            ->assertJsonPath('data.required', false)
            ->assertJsonPath('data.paid', true);
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/submit")->assertOk();
    }

    public function test_only_the_listing_owner_can_pay(): void
    {
        $listing = $this->listingWithPackage(295.00);

        $this->actingAs(User::factory()->create(['role' => 'seller']))
            ->postJson("/api/listings/{$listing->id}/checkout")
            ->assertForbidden();
        $this->assertSame(0, Payment::count());
    }

    // --- Payment lists ---

    public function test_admin_sees_all_payments_and_agents_only_their_own(): void
    {
        $listing = $this->listingWithPackage(295.00);
        $this->actingAs($this->agent)->postJson("/api/packages/{$this->paidPackage->id}/checkout")->assertOk();
        $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/checkout")->assertOk();
        $this->signedWebhook($this->sessionEvent('checkout.session.completed', 'cs_test_2', ['amount_total' => 29500]))->assertOk();

        // A payment that has nothing to do with this agent
        $stranger = User::factory()->create(['role' => 'agent']);
        Payment::create([
            'type' => Payment::TYPE_AGENT_PACKAGE, 'user_id' => $stranger->id, 'package_id' => $this->paidPackage->id,
            'amount' => 4999, 'currency' => 'usd', 'status' => 'paid', 'stripe_session_id' => 'cs_other',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.totals.paid.count', 2)
            ->assertJsonPath('data.totals.paid.amount', 344.99);

        $this->actingAs($admin)->getJson('/api/admin/payments?type=listing&status=paid')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.listing.id', $listing->id)
            ->assertJsonPath('data.items.0.agent.id', $this->agent->id);

        // The agent sees their own package payment and the seller's payment for their package.
        $agentView = $this->actingAs($this->agent)->getJson('/api/agent/payments')->assertOk();
        $this->assertSame(2, $agentView->json('data.meta.total'));
        $this->assertEqualsCanonicalizing(['agent_package', 'listing'], array_column($agentView->json('data.items'), 'type'));

        $this->actingAs($this->seller)->getJson('/api/admin/payments')->assertForbidden();
        $this->actingAs($this->seller)->getJson('/api/agent/payments')->assertForbidden();
    }

    public function test_prices_must_be_free_or_chargeable_by_stripe(): void
    {
        foreach (['0.30' => 422, '12.345' => 422, '0' => 201, '0.50' => 201, '295.00' => 201] as $price => $status) {
            $this->actingAs($this->agent)
                ->postJson('/api/agent/service-packages', ['name' => "Package {$price}", 'price' => $price])
                ->assertStatus($status);
        }
    }

    public function test_checkout_is_refused_when_stripe_is_not_configured(): void
    {
        config(['services.stripe.secret' => null]);

        $this->actingAs($this->agent)
            ->postJson("/api/packages/{$this->paidPackage->id}/checkout")
            ->assertStatus(503);
        $this->assertSame(0, Payment::count());
    }
}
