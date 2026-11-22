<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Package;
use App\Models\Payment;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stripe Checkout payments for agent packages and listings.
 *
 * Security rules:
 * - Amounts always come from the database price, never from the request.
 * - A payment becomes "paid" only from Stripe data fetched server-side (signed webhook or a
 *   session retrieved with the secret key), and only if amount and currency match.
 * - Fulfilment (package activation) runs once, under a row lock, so the webhook and the
 *   return page can't both activate it.
 */
class PaymentService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PackageActivationService $activation,
        private readonly StripeCatalogService $catalog,
        private readonly FeeCalculator $fees,
    ) {}

    public static function toCents(mixed $price): int
    {
        return (int) round(((float) $price) * 100);
    }

    public static function isFree(mixed $price): bool
    {
        return self::toCents($price) <= 0;
    }

    /**
     * Checkout for an agent paying for an admin-defined agent package.
     */
    public function startAgentPackageCheckout(User $agent, Package $package): array
    {
        $amount = self::toCents($package->price);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['package' => 'This package is free and does not need payment.']);
        }

        return $this->startCheckout([
            'type' => Payment::TYPE_AGENT_PACKAGE,
            'user_id' => $agent->id,
            'package_id' => $package->id,
            'amount' => $amount,
            'description' => "Agent package: {$package->name} ({$package->duration_days} days)",
        ], $agent, $package);
    }

    /**
     * Checkout for a seller paying for the agent's service package on their listing.
     */
    public function startListingCheckout(User $seller, Listing $listing): array
    {
        $servicePackage = $listing->servicePackage;
        if (! $servicePackage) {
            throw ValidationException::withMessages(['listing' => 'This listing has no service package to pay for.']);
        }

        $amount = self::toCents($servicePackage->price);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['listing' => 'This service package is free and does not need payment.']);
        }

        if ($this->listingIsPaid($listing)) {
            throw ValidationException::withMessages(['listing' => 'This listing is already paid.']);
        }

        // The platform collects the whole payment; the agent's share is recorded below and
        // withdrawn later (paid by bank transfer), so the agent needs no Stripe setup to be paid.
        // Agent pays both fees: platform fee + Stripe processing fee
        $split = $this->fees->split($amount);

        return $this->startCheckout([
            'type' => Payment::TYPE_LISTING,
            'user_id' => $seller->id,
            'listing_id' => $listing->id,
            'service_package_id' => $servicePackage->id,
            'agent_id' => $servicePackage->agent_id,
            'amount' => $amount,
            'platform_fee' => $split['platform_fee'],
            'processing_fee' => $split['processing_fee'],
            'agent_amount' => $split['agent_amount'],
            'description' => "Listing {$listing->reference_code}: {$servicePackage->name}",
        ], $seller, $servicePackage);
    }

    /**
     * True when the listing needs no payment (free or no package) or its package is paid.
     */
    public function listingIsPaid(Listing $listing): bool
    {
        $servicePackage = $listing->servicePackage;
        if (! $servicePackage || self::isFree($servicePackage->price)) {
            return true;
        }

        return Payment::where('type', Payment::TYPE_LISTING)
            ->where('listing_id', $listing->id)
            ->where('service_package_id', $servicePackage->id)
            ->where('status', Payment::STATUS_PAID)
            ->exists();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model&\App\Models\Concerns\SyncsToStripe  $plan
     */
    private function startCheckout(array $attributes, User $payer, \Illuminate\Database\Eloquent\Model $plan): array
    {
        $currency = config('services.stripe.currency', 'usd');
        $frontend = rtrim(config('app.frontend_url'), '/');

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'customer_email' => $payer->email,
            'line_items' => [$this->lineItem($plan, $attributes, $currency)],
            // IDs only (no personal data) so a payment can be traced from the Stripe dashboard
            'metadata' => array_filter([
                'type' => $attributes['type'],
                'user_id' => (string) $payer->id,
                'package_id' => $attributes['package_id'] ?? null,
                'listing_id' => isset($attributes['listing_id']) ? (string) $attributes['listing_id'] : null,
            ]),
            'success_url' => "{$frontend}/payments/result?session_id={CHECKOUT_SESSION_ID}",
            'cancel_url' => "{$frontend}/payments/result?session_id={CHECKOUT_SESSION_ID}&canceled=1",
        ] + $this->listingIntentMetadata($attributes), 'checkout-'.$attributes['type'].'-'.Str::uuid());

        $payment = Payment::create($attributes + [
            // Agent package payments stay entirely with the platform
            'agent_amount' => 0,
            'currency' => $currency,
            'status' => Payment::STATUS_PENDING,
            'stripe_session_id' => $session['id'],
        ]);

        return ['payment' => $payment, 'checkout_url' => $session['url']];
    }

    /**
     * Seller payments stay entirely in the platform account (no split at payment time);
     * the agent withdraws their share later. Only tag the payment so it can be traced.
     */
    private function listingIntentMetadata(array $attributes): array
    {
        if ($attributes['type'] !== Payment::TYPE_LISTING) {
            return [];
        }

        return ['payment_intent_data' => [
            'metadata' => ['type' => Payment::TYPE_LISTING, 'listing_id' => (string) $attributes['listing_id']],
        ]];
    }

    /**
     * Charge the plan's Stripe Price. If the background sync hasn't run yet, sync now; if Stripe
     * still has no Price for this exact amount, fall back to an inline price. Either way the
     * amount is the database price.
     */
    private function lineItem(\Illuminate\Database\Eloquent\Model $plan, array $attributes, string $currency): array
    {
        if (! $plan->hasCurrentStripePrice()) {
            $this->catalog->sync($plan);
            $plan->refresh();
        }

        if ($plan->hasCurrentStripePrice() && (int) $plan->stripe_price_amount === (int) $attributes['amount']) {
            return ['quantity' => 1, 'price' => $plan->stripe_price_id];
        }

        return [
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => $attributes['amount'],
                'product_data' => ['name' => $plan->name, 'description' => $attributes['description']],
            ],
        ];
    }

    /**
     * Re-check a session with Stripe and record the result. Safe to call any number of times.
     */
    public function syncFromStripe(Payment $payment): Payment
    {
        if (in_array($payment->status, [Payment::STATUS_PAID, Payment::STATUS_REFUNDED], true)) {
            return $payment;
        }

        return $this->applySession($this->stripe->retrieveCheckoutSession($payment->stripe_session_id)) ?? $payment;
    }

    /**
     * Apply a Checkout Session (from a verified webhook or a server-side retrieve).
     */
    public function applySession(array $session): ?Payment
    {
        return DB::transaction(function () use ($session) {
            $payment = Payment::where('stripe_session_id', $session['id'])->lockForUpdate()->first();
            if (! $payment || $payment->isPaid() || $payment->status === Payment::STATUS_REFUNDED) {
                return $payment;
            }

            if (($session['status'] ?? null) === 'expired') {
                $payment->update(['status' => Payment::STATUS_EXPIRED]);

                return $payment;
            }

            if (($session['payment_status'] ?? null) !== 'paid') {
                return $payment;
            }

            if ((int) ($session['amount_total'] ?? -1) !== $payment->amount
                || strtolower((string) ($session['currency'] ?? '')) !== strtolower($payment->currency)) {
                Log::critical('Stripe amount/currency mismatch; payment not fulfilled.', [
                    'payment_id' => $payment->id,
                    'expected' => [$payment->amount, $payment->currency],
                    'received' => [$session['amount_total'] ?? null, $session['currency'] ?? null],
                ]);
                $payment->update(['status' => Payment::STATUS_FAILED]);

                return $payment;
            }

            $payment->fill([
                'status' => Payment::STATUS_PAID,
                'paid_at' => now(),
                'stripe_payment_intent_id' => $session['payment_intent'] ?? null,
            ]);

            if ($payment->type === Payment::TYPE_AGENT_PACKAGE && $payment->package && $payment->user) {
                $payment->agent_package_id = $this->activation->activate($payment->user, $payment->package)->id;
            }

            $payment->save();

            // Paying for the listing's service package publishes it (runs once: paid payments return early above)
            if ($payment->type === Payment::TYPE_LISTING && $payment->listing) {
                $payment->listing->publish();
                // Paid: the full document set (Reozom createDocumentsForListing at payment)
                app(DisclosureService::class)->provisionDocuments($payment->listing->fresh());
            }

            return $payment;
        });
    }

    public function markFailed(string $sessionId): void
    {
        Payment::where('stripe_session_id', $sessionId)
            ->where('status', Payment::STATUS_PENDING)
            ->update(['status' => Payment::STATUS_FAILED]);
    }

    /**
     * Refunds are made in the Stripe dashboard; the webhook records them here.
     */
    public function markRefunded(string $paymentIntentId): void
    {
        Payment::where('stripe_payment_intent_id', $paymentIntentId)
            ->where('status', Payment::STATUS_PAID)
            ->get()
            ->each(function (Payment $payment) {
                $payment->update(['status' => Payment::STATUS_REFUNDED, 'refunded_at' => now()]);

                // A refunded agent package stops giving access
                if ($payment->type === Payment::TYPE_AGENT_PACKAGE && $payment->agentPackage && $payment->agentPackage->expires_at > now()) {
                    $payment->agentPackage->update(['expires_at' => now()]);
                }
            });
    }
}
