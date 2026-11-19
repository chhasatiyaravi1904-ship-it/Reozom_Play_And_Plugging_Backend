<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A price is either free (empty / 0) or at least Stripe's minimum charge ($0.50 USD),
 * with at most 2 decimals, so every paid price can actually be charged.
 */
class StripeChargeablePrice implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_numeric($value) || ! preg_match('/^\d+(\.\d{1,2})?$/', (string) $value)) {
            $fail('The price must be an amount like 49 or 49.99.');

            return;
        }

        $amount = (float) $value;
        if ($amount > 0 && $amount < 0.5) {
            $fail('Paid prices must be at least $0.50 (Stripe minimum). Use 0 for free.');
        } elseif ($amount > 999999.99) {
            $fail('The price is too high.');
        }
    }
}
