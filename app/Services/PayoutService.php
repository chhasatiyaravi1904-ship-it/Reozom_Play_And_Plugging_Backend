<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent withdrawals: manual Stripe payouts from the agent's Connect account to their bank.
 *
 * Security: always the logged-in agent's own account; the amount is checked against the
 * balance Stripe reports (Stripe also refuses overdrafts); the agent row is locked so two
 * clicks can't create two payouts; status only changes from Stripe (webhook).
 */
class PayoutService
{
    /** Smallest withdrawal, in cents. */
    public const MIN_WITHDRAWAL = 100;

    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * @return array{available: int, pending: int}
     */
    public function balance(User $agent): array
    {
        if (! $agent->stripe_account_id) {
            return ['available' => 0, 'pending' => 0];
        }

        return $this->stripe->retrieveConnectBalance($agent->stripe_account_id, $this->currency());
    }

    public function withdraw(User $agent, int $amount): Payout
    {
        if (! $agent->canReceivePayments() || ! $agent->stripe_payouts_enabled) {
            throw ValidationException::withMessages(['amount' => 'Finish setting up payouts before withdrawing.']);
        }
        if ($amount < self::MIN_WITHDRAWAL) {
            throw ValidationException::withMessages(['amount' => 'The minimum withdrawal is $1.00.']);
        }

        return DB::transaction(function () use ($agent, $amount) {
            User::whereKey($agent->id)->lockForUpdate()->first();

            $available = $this->balance($agent)['available'];
            if ($amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => sprintf('You can withdraw up to $%s right now.', number_format($available / 100, 2)),
                ]);
            }

            $result = $this->stripe->createConnectPayout(
                $agent->stripe_account_id, $amount, $this->currency(), 'payout-'.$agent->id.'-'.Str::uuid()
            );

            return Payout::create([
                'agent_id' => $agent->id,
                'stripe_account_id' => $agent->stripe_account_id,
                'stripe_payout_id' => $result['id'],
                'amount' => $amount,
                'currency' => $this->currency(),
                'status' => $this->normalizeStatus($result['status']),
                'arrival_date' => $result['arrival_date'] ? Carbon::createFromTimestamp($result['arrival_date'])->toDateString() : null,
            ]);
        });
    }

    /**
     * Apply a payout event from the Connect webhook. The account must match the payout's agent.
     */
    public function applyPayoutEvent(array $payout, ?string $accountId): ?Payout
    {
        $record = Payout::where('stripe_payout_id', $payout['id'])->first();
        if (! $record || ($accountId && $record->stripe_account_id !== $accountId)) {
            return null;
        }

        $status = $this->normalizeStatus($payout['status'] ?? $record->status);
        $record->update([
            'status' => $status,
            'failure_message' => $payout['failure_message'] ?? null,
            'arrival_date' => isset($payout['arrival_date']) ? Carbon::createFromTimestamp($payout['arrival_date'])->toDateString() : $record->arrival_date,
            'paid_at' => $status === Payout::STATUS_PAID ? ($record->paid_at ?? now()) : $record->paid_at,
        ]);

        return $record;
    }

    private function normalizeStatus(?string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'paid' => Payout::STATUS_PAID,
            'failed' => Payout::STATUS_FAILED,
            'canceled' => Payout::STATUS_CANCELED,
            'in_transit' => Payout::STATUS_IN_TRANSIT,
            default => Payout::STATUS_PENDING,
        };
    }

    private function currency(): string
    {
        return config('services.stripe.currency', 'usd');
    }
}
