<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Package;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly StripeGateway $stripe,
    ) {}

    /**
     * Agent starts paying for an agent package. Returns the Stripe Checkout URL to redirect to.
     */
    public function agentPackageCheckout(Request $request, Package $package): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAgent()) {
            return api_error('Only agents can buy a package.', 403);
        }
        if (! $package->is_active) {
            return api_error('This package is not available.', 422);
        }

        $current = $user->currentAgentPackage;
        if ($current && $current->package_id === $package->id) {
            return api_error('You already have this package active.', 422);
        }

        return $this->checkout(fn () => $this->payments->startAgentPackageCheckout($user, $package));
    }

    /**
     * Seller starts paying for the agent's service package on their listing.
     */
    public function listingCheckout(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        if ($request->user()->id !== $listing->user_id) {
            return api_error('Only the listing owner can pay for this listing.', 403);
        }

        return $this->checkout(fn () => $this->payments->startListingCheckout($request->user(), $listing));
    }

    /**
     * Payment step summary for a listing: what is owed and whether it is paid.
     */
    public function listingStatus(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $servicePackage = $listing->servicePackage;
        $amount = $servicePackage ? PaymentService::toCents($servicePackage->price) : 0;

        return api_success([
            'required' => $amount > 0,
            'paid' => $this->payments->listingIsPaid($listing),
            'amount' => $amount / 100,
            'currency' => config('services.stripe.currency', 'usd'),
            'package' => $servicePackage ? ['id' => $servicePackage->id, 'name' => $servicePackage->name] : null,
            'can_pay' => $request->user()->id === $listing->user_id,
            'payment_available' => $this->stripe->isConfigured(),
            // Seller payments go to the agent's Stripe account; false until the agent finishes payout setup
            'agent_ready' => (bool) $servicePackage?->agent?->canReceivePayments(),
        ]);
    }

    /**
     * Return page after Stripe: re-checks the session with Stripe (server-side) and returns the
     * result. Only the payer can look up their own session.
     */
    public function showSession(Request $request, string $sessionId): JsonResponse
    {
        $payment = Payment::where('stripe_session_id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $payment) {
            return api_error('Payment not found.', 404);
        }

        try {
            $payment = $this->payments->syncFromStripe($payment);
        } catch (Throwable $e) {
            // Keep the stored status; the webhook will still update it.
            Log::warning('Stripe session sync failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        return api_success($this->summary($payment->fresh()));
    }

    /**
     * Stripe webhook. Public route; trusted only after the Stripe-Signature check passes.
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            $event = $this->stripe->constructWebhookEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', '')
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\RuntimeException $e) {
            Log::error('Stripe webhook not configured', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook not configured.'], 500);
        }

        $object = $event->data->object;

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
            case 'checkout.session.expired':
                $this->payments->applySession(StripeGateway::sessionToArray($object));
                break;
            case 'checkout.session.async_payment_failed':
                $this->payments->markFailed($object->id);
                break;
            case 'charge.refunded':
                if ($object->refunded && is_string($object->payment_intent ?? null)) {
                    $this->payments->markRefunded($object->payment_intent);
                }
                break;
        }

        // 200 for every verified event so Stripe doesn't retry ones we don't handle.
        return response()->json(['received' => true]);
    }

    private function checkout(callable $start): JsonResponse
    {
        if (! $this->stripe->isConfigured()) {
            return api_error('Online payment is not configured yet. Please contact support.', 503);
        }

        try {
            $result = $start();
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe checkout failed', ['error' => $e->getMessage()]);

            return api_error('Could not start the payment. Please try again.', 502);
        }

        return api_success([
            'checkout_url' => $result['checkout_url'],
            'payment' => $this->summary($result['payment']),
        ], 'Redirecting to secure payment.');
    }

    private function summary(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'type' => $payment->type,
            'status' => $payment->status,
            'amount' => $payment->amountDecimal(),
            'currency' => $payment->currency,
            'description' => $payment->description,
            'package_id' => $payment->package_id,
            'listing_id' => $payment->listing_id,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
        ];
    }
}
