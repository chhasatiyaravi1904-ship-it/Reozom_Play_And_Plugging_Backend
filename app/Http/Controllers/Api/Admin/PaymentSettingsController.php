<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Services\FeeCalculator;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Admin: platform fee settings and refunds.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(
        private readonly FeeCalculator $fees,
        private readonly StripeGateway $stripe,
    ) {}

    public function show(): JsonResponse
    {
        return api_success($this->settings());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform_fee_percent' => ['required', 'numeric', 'min:0', 'max:50', 'regex:/^\d+(\.\d{1,2})?$/'],
            'platform_fee_fixed' => ['nullable', 'numeric', 'min:0', 'max:1000', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        PlatformSetting::setValue(PlatformSetting::PLATFORM_FEE_PERCENT, (float) $data['platform_fee_percent']);
        PlatformSetting::setValue(PlatformSetting::PLATFORM_FEE_FIXED_CENTS, (int) round(((float) ($data['platform_fee_fixed'] ?? 0)) * 100));

        return api_success($this->settings(), 'Payment settings saved. They apply to new seller payments.');
    }

    /**
     * Refund a paid payment through Stripe. For seller payments the agent's share is pulled
     * back from the agent's account (reverse transfer); the webhook then marks it refunded.
     */
    public function refund(Payment $payment): JsonResponse
    {
        if (! $payment->isPaid() || ! $payment->stripe_payment_intent_id) {
            return api_error('Only paid payments can be refunded.', 422);
        }
        if ($payment->stripe_refund_id) {
            return api_error('A refund has already been requested for this payment.', 422);
        }
        if (! $this->stripe->isConfigured()) {
            return api_error('Stripe is not configured.', 503);
        }

        try {
            $refundId = $this->stripe->refundPayment(
                $payment->stripe_payment_intent_id,
                $payment->type === Payment::TYPE_LISTING,
                "refund-payment-{$payment->id}"
            );
        } catch (ApiErrorException $e) {
            Log::error('Stripe refund failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            return api_error('Stripe could not refund this payment: '.$e->getMessage(), 502);
        }

        $payment->update(['stripe_refund_id' => $refundId]);

        return api_success(['id' => $payment->id, 'stripe_refund_id' => $refundId], 'Refund requested. The status updates when Stripe confirms it.');
    }

    private function settings(): array
    {
        $example = 29500;

        return [
            'platform_fee_percent' => $this->fees->platformFeePercent(),
            'platform_fee_fixed' => $this->fees->platformFeeFixedCents() / 100,
            'processing_fee_percent' => (float) config('services.stripe.processing_fee_percent', 2.9),
            'processing_fee_fixed' => (int) config('services.stripe.processing_fee_fixed_cents', 30) / 100,
            'example' => collect($this->safeSplit($example))->map(fn ($v) => $v / 100)->all(),
        ];
    }

    private function safeSplit(int $amount): array
    {
        try {
            return $this->fees->split($amount);
        } catch (\Throwable) {
            return ['amount' => $amount, 'platform_fee' => 0, 'processing_fee' => 0, 'agent_amount' => 0];
        }
    }
}
