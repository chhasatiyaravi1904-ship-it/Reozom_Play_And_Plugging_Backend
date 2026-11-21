<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\WithdrawalProcessedNotification;
use App\Notifications\WithdrawalRequestedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Agent withdrawals. Sellers pay the platform (admin Stripe account); each paid listing payment
 * records the agent's share. The agent requests a withdrawal of that balance and an admin pays it
 * by bank transfer, then marks it paid (with the transfer reference) or rejects it.
 *
 * Security: always the logged-in agent's own balance; the agent row is locked so two clicks can't
 * over-draw; only pending requests can be paid, rejected or canceled.
 */
class PayoutService
{
    /** Smallest withdrawal, in cents. */
    public const MIN_WITHDRAWAL = 100;

    /**
     * Balance in cents. Only payments the platform collected count (old split payments already
     * went to the agent's Stripe account). Pending and paid requests are reserved.
     *
     * @return array{earned: int, pending: int, withdrawn: int, available: int}
     */
    public function balance(User $agent): array
    {
        $earned = (int) Payment::where('type', Payment::TYPE_LISTING)
            ->where('agent_id', $agent->id)
            ->where('status', Payment::STATUS_PAID)
            ->whereNull('destination_account_id')
            ->sum('agent_amount');

        $manual = Payout::where('agent_id', $agent->id)->where('method', Payout::METHOD_MANUAL);
        $pending = (int) (clone $manual)->where('status', Payout::STATUS_PENDING)->sum('amount');
        $withdrawn = (int) (clone $manual)->where('status', Payout::STATUS_PAID)->sum('amount');

        return [
            'earned' => $earned,
            'pending' => $pending,
            'withdrawn' => $withdrawn,
            // A refund after a payout can push this below zero; it is then made up by later earnings
            'available' => max(0, $earned - $pending - $withdrawn),
        ];
    }

    /** The agent asks for $amount cents to be sent to their saved bank account. */
    public function requestWithdrawal(User $agent, int $amount): Payout
    {
        $bank = $agent->bankAccount;
        if (! $bank) {
            throw ValidationException::withMessages(['amount' => 'Add your bank details before requesting a withdrawal.']);
        }
        if ($amount < self::MIN_WITHDRAWAL) {
            throw ValidationException::withMessages(['amount' => 'The minimum withdrawal is $1.00.']);
        }

        $payout = DB::transaction(function () use ($agent, $bank, $amount) {
            User::whereKey($agent->id)->lockForUpdate()->first();

            $available = $this->balance($agent)['available'];
            if ($amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => sprintf('You can withdraw up to $%s right now.', number_format($available / 100, 2)),
                ]);
            }

            return Payout::create([
                'agent_id' => $agent->id,
                'method' => Payout::METHOD_MANUAL,
                'amount' => $amount,
                'currency' => $this->currency(),
                'status' => Payout::STATUS_PENDING,
                // The account it must be paid into, even if the agent edits their details later
                'bank_details' => $bank->snapshot(),
            ]);
        });

        $admins = User::where('role', 'admin')->where('is_active', true)->get();
        Notification::send($admins, new WithdrawalRequestedNotification($payout));

        return $payout;
    }

    public function cancel(Payout $payout, User $agent): Payout
    {
        $this->assertPendingManual($payout);
        if ($payout->agent_id !== $agent->id) {
            abort(403);
        }

        $payout->update(['status' => Payout::STATUS_CANCELED, 'processed_at' => now()]);

        return $payout;
    }

    public function markPaid(Payout $payout, User $admin, string $reference, ?string $paidOn = null): Payout
    {
        $this->assertPendingManual($payout);

        $payout->update([
            'status' => Payout::STATUS_PAID,
            'reference' => $reference,
            'paid_at' => $paidOn ? Carbon::parse($paidOn)->startOfDay() : now(),
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);
        $payout->agent?->notify(new WithdrawalProcessedNotification($payout));

        return $payout;
    }

    public function reject(Payout $payout, User $admin, string $reason): Payout
    {
        $this->assertPendingManual($payout);

        $payout->update([
            'status' => Payout::STATUS_REJECTED,
            'failure_message' => $reason,
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ]);
        $payout->agent?->notify(new WithdrawalProcessedNotification($payout));

        return $payout;
    }

    private function assertPendingManual(Payout $payout): void
    {
        if ($payout->method !== Payout::METHOD_MANUAL || ! $payout->isPending()) {
            throw ValidationException::withMessages(['payout' => 'Only pending withdrawal requests can be changed.']);
        }
    }

    /**
     * Apply a payout event from the Connect webhook (old Stripe Connect payouts only).
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
