<?php

namespace App\Services;

use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * The only class that talks to Stripe. Kept thin so tests can swap it for a fake.
 */
class StripeGateway
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    private function client(): StripeClient
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Stripe is not configured. Set STRIPE_SECRET in .env.');
        }

        // The SDK retries network failures with an automatic idempotency key, so a retry
        // can never create a second Checkout Session or charge.
        return $this->client ??= new StripeClient([
            'api_key' => config('services.stripe.secret'),
            'max_network_retries' => 2,
        ]);
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        $session = $this->client()->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey]);

        return ['id' => $session->id, 'url' => $session->url];
    }

    // --- Connect (agent payout accounts) ---

    public function createConnectAccount(array $params, string $idempotencyKey): string
    {
        return $this->client()->accounts->create($params, ['idempotency_key' => $idempotencyKey])->id;
    }

    /** One-time link to Stripe's hosted onboarding (expires after a few minutes). */
    public function createAccountOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->client()->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])->url;
    }

    /** One-time link into the agent's Stripe Express dashboard. */
    public function createDashboardLink(string $accountId): string
    {
        return $this->client()->accounts->createLoginLink($accountId)->url;
    }

    /**
     * @return array{id: string, details_submitted: bool, charges_enabled: bool, payouts_enabled: bool, disabled_reason: ?string, requirements_due: int}
     */
    public function retrieveConnectAccount(string $accountId): array
    {
        return self::accountToArray($this->client()->accounts->retrieve($accountId));
    }

    public static function accountToArray(\Stripe\StripeObject $account): array
    {
        $requirements = $account->requirements ?? null;

        return [
            'id' => $account->id,
            'details_submitted' => (bool) ($account->details_submitted ?? false),
            'charges_enabled' => (bool) ($account->charges_enabled ?? false),
            'payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
            'disabled_reason' => $requirements->disabled_reason ?? null,
            'requirements_due' => count($requirements->currently_due ?? []) + count($requirements->past_due ?? []),
        ];
    }

    /**
     * Verify a Connect webhook (events from agents' accounts use their own signing secret).
     */
    public function constructConnectWebhookEvent(string $payload, string $signature): Event
    {
        $secret = config('services.stripe.connect_webhook_secret');
        if (blank($secret)) {
            throw new RuntimeException('Stripe Connect webhook secret is not configured. Set STRIPE_CONNECT_WEBHOOK_SECRET in .env.');
        }

        return Webhook::constructEvent($payload, $signature, $secret);
    }

    // --- Agent balance & payouts (on the agent's Connect account) ---

    /**
     * Agent's balance in one currency, in cents.
     *
     * @return array{available: int, pending: int}
     */
    public function retrieveConnectBalance(string $accountId, string $currency): array
    {
        $balance = $this->client()->balance->retrieve([], ['stripe_account' => $accountId]);

        $sum = fn ($entries) => (int) collect($entries ?? [])
            ->filter(fn ($e) => strtolower($e->currency) === strtolower($currency))
            ->sum('amount');

        return ['available' => $sum($balance->available), 'pending' => $sum($balance->pending)];
    }

    /**
     * Pay out from the agent's Stripe balance to their bank account.
     *
     * @return array{id: string, status: string, arrival_date: ?int}
     */
    public function createConnectPayout(string $accountId, int $amount, string $currency, string $idempotencyKey): array
    {
        $payout = $this->client()->payouts->create(
            ['amount' => $amount, 'currency' => $currency],
            ['stripe_account' => $accountId, 'idempotency_key' => $idempotencyKey]
        );

        return ['id' => $payout->id, 'status' => $payout->status, 'arrival_date' => $payout->arrival_date];
    }

    // --- Refunds ---

    /**
     * Refund a seller payment. reverse_transfer takes the agent's share back from their account;
     * the platform keeps its application fee to cover Stripe's non-refundable processing fee.
     */
    public function refundPayment(string $paymentIntentId, bool $reverseTransfer, string $idempotencyKey): string
    {
        $params = ['payment_intent' => $paymentIntentId];
        if ($reverseTransfer) {
            $params['reverse_transfer'] = true;
        }

        return $this->client()->refunds->create($params, ['idempotency_key' => $idempotencyKey])->id;
    }

    // --- Catalog (Products / Prices) ---

    public function createProduct(array $params, string $idempotencyKey): string
    {
        return $this->client()->products->create($params, ['idempotency_key' => $idempotencyKey])->id;
    }

    public function updateProduct(string $productId, array $params): void
    {
        $this->client()->products->update($productId, $params);
    }

    public function createPrice(array $params, string $idempotencyKey): string
    {
        return $this->client()->prices->create($params, ['idempotency_key' => $idempotencyKey])->id;
    }

    /** Prices can't be deleted or edited; archiving stops new purchases at that price. */
    public function archivePrice(string $priceId): void
    {
        $this->client()->prices->update($priceId, ['active' => false]);
    }

    /**
     * Fresh copy of a Checkout Session straight from Stripe (never trust the browser's word).
     *
     * @return array{id: string, status: ?string, payment_status: ?string, amount_total: ?int, currency: ?string, payment_intent: ?string}
     */
    public function retrieveCheckoutSession(string $sessionId): array
    {
        return self::sessionToArray($this->client()->checkout->sessions->retrieve($sessionId));
    }

    /**
     * Verify the Stripe-Signature header and build the event. Throws on a bad signature.
     */
    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        $secret = config('services.stripe.webhook_secret');
        if (blank($secret)) {
            throw new RuntimeException('Stripe webhook secret is not configured. Set STRIPE_WEBHOOK_SECRET in .env.');
        }

        return Webhook::constructEvent($payload, $signature, $secret);
    }

    public static function sessionToArray(Session|\Stripe\StripeObject $session): array
    {
        $intent = $session->payment_intent ?? null;

        return [
            'id' => $session->id,
            'status' => $session->status ?? null,
            'payment_status' => $session->payment_status ?? null,
            'amount_total' => $session->amount_total ?? null,
            'currency' => $session->currency ?? null,
            'payment_intent' => is_string($intent) ? $intent : ($intent->id ?? null),
        ];
    }
}
