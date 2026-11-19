<?php

namespace Tests\Support;

use App\Services\StripeGateway;
use RuntimeException;

/**
 * Stripe stand-in for tests: records every call and returns predictable IDs.
 * Webhook signature checking is NOT faked (the real StripeGateway method is used).
 */
class FakeStripeGateway extends StripeGateway
{
    public array $createdSessions = [];

    /** @var array<string, array> Session overrides returned by retrieveCheckoutSession */
    public array $sessions = [];

    /** @var array<string, array> product id => params (merged on update) */
    public array $products = [];

    /** @var array<string, array> price id => params (+ 'active') */
    public array $prices = [];

    /** When set, catalog calls throw this message (simulates Stripe being down). */
    public ?string $failCatalogWith = null;

    public int $productCreates = 0;

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        $this->createdSessions[] = $params;
        $id = 'cs_test_'.count($this->createdSessions);

        return ['id' => $id, 'url' => "https://checkout.stripe.test/{$id}"];
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        $index = (int) substr($sessionId, strlen('cs_test_')) - 1;
        $item = $this->createdSessions[$index]['line_items'][0] ?? [];
        $amount = $item['price_data']['unit_amount']
            ?? (isset($item['price']) ? ($this->prices[$item['price']]['unit_amount'] ?? 0) : 0);

        return array_merge([
            'id' => $sessionId,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'amount_total' => $amount,
            'currency' => 'usd',
            'payment_intent' => 'pi_from_retrieve',
        ], $this->sessions[$sessionId] ?? []);
    }

    /** @var array<string, array> Connect account id => create params */
    public array $accounts = [];

    /** @var array<string, array> Account state returned by retrieveConnectAccount */
    public array $accountStates = [];

    public function createConnectAccount(array $params, string $idempotencyKey): string
    {
        $id = 'acct_test_'.(count($this->accounts) + 1);
        $this->accounts[$id] = $params;

        return $id;
    }

    public function createAccountOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return "https://connect.stripe.test/setup/{$accountId}";
    }

    public function createDashboardLink(string $accountId): string
    {
        return "https://connect.stripe.test/express/{$accountId}";
    }

    public function retrieveConnectAccount(string $accountId): array
    {
        return array_merge([
            'id' => $accountId,
            'details_submitted' => false,
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'disabled_reason' => 'requirements.past_due',
            'requirements_due' => 3,
        ], $this->accountStates[$accountId] ?? []);
    }

    /** @var array<string, array{available: int, pending: int}> account => balance (cents) */
    public array $balances = [];

    /** @var array<int, array> payouts created: [account, amount, currency, key] */
    public array $payoutsCreated = [];

    /** @var array<int, array> refunds created: [payment_intent, reverse_transfer] */
    public array $refunds = [];

    public function retrieveConnectBalance(string $accountId, string $currency): array
    {
        return $this->balances[$accountId] ?? ['available' => 0, 'pending' => 0];
    }

    public function createConnectPayout(string $accountId, int $amount, string $currency, string $idempotencyKey): array
    {
        $this->payoutsCreated[] = compact('accountId', 'amount', 'currency', 'idempotencyKey');
        $this->balances[$accountId]['available'] = ($this->balances[$accountId]['available'] ?? 0) - $amount;

        return ['id' => 'po_test_'.count($this->payoutsCreated), 'status' => 'pending', 'arrival_date' => strtotime('+2 days')];
    }

    public function refundPayment(string $paymentIntentId, bool $reverseTransfer, string $idempotencyKey): string
    {
        $this->refunds[] = ['payment_intent' => $paymentIntentId, 'reverse_transfer' => $reverseTransfer];

        return 're_test_'.count($this->refunds);
    }

    public function createProduct(array $params, string $idempotencyKey): string
    {
        $this->guard();
        $this->productCreates++;
        $id = 'prod_test_'.(count($this->products) + 1);
        $this->products[$id] = $params + ['active' => true];

        return $id;
    }

    public function updateProduct(string $productId, array $params): void
    {
        $this->guard();
        $this->products[$productId] = array_merge($this->products[$productId] ?? [], $params);
    }

    public function createPrice(array $params, string $idempotencyKey): string
    {
        $this->guard();
        $id = 'price_test_'.(count($this->prices) + 1);
        $this->prices[$id] = $params + ['active' => true];

        return $id;
    }

    public function archivePrice(string $priceId): void
    {
        $this->guard();

        // Same rule as real Stripe (found in a live test-mode run)
        $product = $this->prices[$priceId]['product'] ?? null;
        if ($product && ($this->products[$product]['default_price'] ?? null) === $priceId) {
            throw new RuntimeException('This price cannot be archived because it is the default price of its product.');
        }

        $this->prices[$priceId]['active'] = false;
    }

    private function guard(): void
    {
        if ($this->failCatalogWith !== null) {
            throw new RuntimeException($this->failCatalogWith);
        }
    }
}
