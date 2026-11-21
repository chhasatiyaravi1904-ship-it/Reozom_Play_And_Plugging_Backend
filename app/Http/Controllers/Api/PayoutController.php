<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentBankAccount;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\AdminActivityLogger;
use App\Services\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agent balance, bank details and withdrawal requests (paid manually by an admin),
 * and the admin's side: the request list, Mark paid and Reject.
 */
class PayoutController extends Controller
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly AdminActivityLogger $activity,
    ) {}

    /**
     * Agent: balance, earnings, bank account (masked) and withdrawal history.
     */
    public function agentIndex(Request $request): JsonResponse
    {
        $agent = $request->user();
        $balance = $this->payouts->balance($agent);

        $earned = Payment::where('type', Payment::TYPE_LISTING)
            ->where('agent_id', $agent->id)
            ->where('status', Payment::STATUS_PAID)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as gross, COALESCE(SUM(platform_fee),0) as platform_fee, COALESCE(SUM(processing_fee),0) as processing_fee, COALESCE(SUM(agent_amount),0) as net')
            ->first();

        return api_success([
            'currency' => config('services.stripe.currency', 'usd'),
            'balance' => [
                'available' => $balance['available'] / 100,
                'pending' => $balance['pending'] / 100,
                'withdrawn' => $balance['withdrawn'] / 100,
            ],
            'min_withdrawal' => PayoutService::MIN_WITHDRAWAL / 100,
            'bank_account' => $agent->bankAccount?->masked(),
            'earnings' => [
                'sales' => (int) $earned->count,
                'gross' => $earned->gross / 100,
                'platform_fee' => $earned->platform_fee / 100,
                'processing_fee' => $earned->processing_fee / 100,
                'net' => $earned->net / 100,
            ],
            'payouts' => Payout::where('agent_id', $agent->id)->latest()->limit(50)->get()
                ->map(fn (Payout $p) => $this->row($p))->all(),
        ]);
    }

    /**
     * Agent: save (create or replace) the bank account withdrawals are paid into.
     */
    public function saveBankAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_holder_name' => ['required', 'string', 'max:120'],
            'bank_name' => ['required', 'string', 'max:120'],
            'routing_number' => ['required', 'digits:9'],
            'account_number' => ['required', 'regex:/^\d{4,17}$/', 'confirmed'],
            'account_type' => ['required', Rule::in(AgentBankAccount::TYPES)],
        ], [
            'account_number.regex' => 'The account number must be 4 to 17 digits.',
            'account_number.confirmed' => 'The account numbers do not match.',
        ]);

        $account = AgentBankAccount::updateOrCreate(
            ['agent_id' => $request->user()->id],
            [
                'account_holder_name' => trim($data['account_holder_name']),
                'bank_name' => trim($data['bank_name']),
                'routing_number' => $data['routing_number'],
                'account_number' => $data['account_number'],
                'account_last4' => substr($data['account_number'], -4),
                'account_type' => $data['account_type'],
            ]
        );

        return api_success($account->masked(), 'Bank details saved.');
    }

    /**
     * Agent: request a withdrawal of part or all of the available balance.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $payout = $this->payouts->requestWithdrawal(
            $request->user()->fresh(),
            (int) round(((float) $data['amount']) * 100)
        );

        return api_success($this->row($payout), 'Withdrawal requested. You will get an email when it has been sent to your bank.', 201);
    }

    /**
     * Agent: cancel their own request while it is still pending.
     */
    public function cancel(Request $request, Payout $payout): JsonResponse
    {
        if ($payout->agent_id !== $request->user()->id) {
            return api_error('Withdrawal not found.', 404);
        }

        return api_success($this->row($this->payouts->cancel($payout, $request->user())), 'Withdrawal request canceled.');
    }

    /**
     * Admin: withdrawal requests. Pending ones include the full bank details to make the transfer.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'nullable|in:pending,in_transit,paid,failed,canceled,rejected',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $page = Payout::with(['agent:id,name,email', 'processor:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            // Pending first: they are the admin's to-do list
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($filters['per_page'] ?? 20);

        return api_success([
            'items' => collect($page->items())->map(fn (Payout $p) => $this->row($p) + [
                'agent' => $p->agent?->only(['id', 'name', 'email']),
                'processed_by' => $p->processor?->name,
                'bank_details' => $p->isPending() ? $p->bank_details : $this->maskedSnapshot($p->bank_details),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'pending_total' => Payout::where('method', Payout::METHOD_MANUAL)->where('status', Payout::STATUS_PENDING)->sum('amount') / 100,
        ]);
    }

    /**
     * Admin: the bank transfer was sent; record its reference.
     */
    public function markPaid(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $this->payouts->markPaid($payout, $request->user(), trim($data['reference']), $data['paid_on'] ?? null);
        $this->activity->log($request->user(), 'payout_marked_paid',
            "Marked withdrawal #{$payout->id} ($".number_format($payout->amount / 100, 2).") as paid, ref {$payout->reference}", $request);

        return api_success($this->row($payout->fresh()), 'Withdrawal marked as paid. The agent has been emailed.');
    }

    /**
     * Admin: reject a request; the amount returns to the agent's balance.
     */
    public function reject(Request $request, Payout $payout): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->payouts->reject($payout, $request->user(), trim($data['reason']));
        $this->activity->log($request->user(), 'payout_rejected',
            "Rejected withdrawal #{$payout->id} ($".number_format($payout->amount / 100, 2).')', $request);

        return api_success($this->row($payout->fresh()), 'Withdrawal rejected. The agent has been emailed.');
    }

    /**
     * Admin: each agent's earnings, balance and whether bank details are on file.
     */
    public function adminAccounts(): JsonResponse
    {
        $agents = User::query()->where('role', 'agent')->with('bankAccount')->orderBy('name')->get(['id', 'name', 'email']);

        $earnings = Payment::where('type', Payment::TYPE_LISTING)->where('status', Payment::STATUS_PAID)
            ->groupBy('agent_id')
            ->selectRaw('agent_id, COALESCE(SUM(amount),0) as gross, COALESCE(SUM(agent_amount),0) as net, COALESCE(SUM(platform_fee),0) as platform_fee')
            ->get()->keyBy('agent_id');

        return api_success($agents->map(function (User $a) use ($earnings) {
            $balance = $this->payouts->balance($a);

            return [
                'id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'has_bank_account' => $a->bankAccount !== null,
                'gross' => ($earnings[$a->id]->gross ?? 0) / 100,
                'net' => ($earnings[$a->id]->net ?? 0) / 100,
                'platform_fee' => ($earnings[$a->id]->platform_fee ?? 0) / 100,
                'available' => $balance['available'] / 100,
                'pending' => $balance['pending'] / 100,
                'withdrawn' => $balance['withdrawn'] / 100,
            ];
        })->values()->all());
    }

    private function row(Payout $p): array
    {
        return [
            'id' => $p->id,
            'method' => $p->method,
            'amount' => $p->amount / 100,
            'currency' => $p->currency,
            'status' => $p->status,
            'reference' => $p->reference,
            // For rejected requests this is the admin's reason
            'failure_message' => $p->failure_message,
            'bank_last4' => substr((string) ($p->bank_details['account_number'] ?? ''), -4) ?: null,
            'arrival_date' => $p->arrival_date?->toDateString(),
            'paid_at' => $p->paid_at?->toIso8601String(),
            'processed_at' => $p->processed_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function maskedSnapshot(?array $bank): ?array
    {
        if (! $bank) {
            return null;
        }

        return [
            'account_holder_name' => $bank['account_holder_name'] ?? null,
            'bank_name' => $bank['bank_name'] ?? null,
            'account_type' => $bank['account_type'] ?? null,
            'routing_number' => '•••••'.substr((string) ($bank['routing_number'] ?? ''), -4),
            'account_number' => '••••'.substr((string) ($bank['account_number'] ?? ''), -4),
        ];
    }
}
