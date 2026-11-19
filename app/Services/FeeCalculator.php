<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Validation\ValidationException;

/**
 * Splits a seller payment (cents). The agent pays both fees:
 *   agent_amount = amount - platform fee - Stripe processing fee
 * The platform fee is an admin setting (percent + optional fixed amount; 0 by default).
 * Stripe's US card fee is 2.9% + 30¢; it is charged to the platform account, so it is
 * recovered from the agent as part of the application fee.
 */
class FeeCalculator
{
    public function platformFeePercent(): float
    {
        return (float) PlatformSetting::getValue(PlatformSetting::PLATFORM_FEE_PERCENT, 0);
    }

    public function platformFeeFixedCents(): int
    {
        return (int) PlatformSetting::getValue(PlatformSetting::PLATFORM_FEE_FIXED_CENTS, 0);
    }

    /**
     * @return array{amount: int, platform_fee: int, processing_fee: int, agent_amount: int}
     */
    public function split(int $amount): array
    {
        $platformFee = (int) round($amount * $this->platformFeePercent() / 100) + $this->platformFeeFixedCents();
        $processingFee = (int) round($amount * (float) config('services.stripe.processing_fee_percent', 2.9) / 100)
            + (int) config('services.stripe.processing_fee_fixed_cents', 30);

        $agentAmount = $amount - $platformFee - $processingFee;
        if ($agentAmount <= 0) {
            throw ValidationException::withMessages([
                'listing' => 'This package price is too low to cover the fees. Please contact the agent.',
            ]);
        }

        return [
            'amount' => $amount,
            'platform_fee' => $platformFee,
            'processing_fee' => $processingFee,
            'agent_amount' => $agentAmount,
        ];
    }
}
