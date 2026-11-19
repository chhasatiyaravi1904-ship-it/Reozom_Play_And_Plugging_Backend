<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agents' Stripe Connect Express payout accounts (US only).
 *
 * Stripe hosts onboarding (identity + bank account); we only store the account ID and the
 * status flags Stripe reports. Payouts are set to "manual" so agents withdraw with a button.
 */
class ConnectService
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * Link to Stripe's onboarding for this agent, creating their account the first time.
     */
    public function onboardingLink(User $agent): string
    {
        if (! $agent->isAgent()) {
            throw ValidationException::withMessages(['account' => 'Only agents can set up payouts.']);
        }

        $accountId = $agent->stripe_account_id ?? $this->createAccount($agent);
        $frontend = rtrim(config('app.frontend_url'), '/');

        return $this->stripe->createAccountOnboardingLink(
            $accountId,
            "{$frontend}/agent/payouts?onboarding=refresh",
            "{$frontend}/agent/payouts?onboarding=return",
        );
    }

    /**
     * Link into the agent's Stripe Express dashboard (statements, bank details, tax forms).
     */
    public function dashboardLink(User $agent): string
    {
        if (! $agent->stripe_account_id || ! $agent->stripe_details_submitted) {
            throw ValidationException::withMessages(['account' => 'Finish setting up payouts first.']);
        }

        return $this->stripe->createDashboardLink($agent->stripe_account_id);
    }

    /**
     * Fetch the account from Stripe and store its status.
     */
    public function refresh(User $agent): User
    {
        if ($agent->stripe_account_id) {
            $this->apply($this->stripe->retrieveConnectAccount($agent->stripe_account_id));
        }

        return $agent->fresh();
    }

    /**
     * Store an account's status (from a verified webhook or a server-side retrieve).
     */
    public function apply(array $account): ?User
    {
        $agent = User::where('stripe_account_id', $account['id'])->first();
        if (! $agent) {
            return null;
        }

        $agent->forceFill([
            'stripe_details_submitted' => $account['details_submitted'],
            'stripe_charges_enabled' => $account['charges_enabled'],
            'stripe_payouts_enabled' => $account['payouts_enabled'],
            'stripe_disabled_reason' => $account['disabled_reason'],
            'stripe_requirements_due' => $account['requirements_due'],
            'stripe_account_updated_at' => now(),
        ])->save();

        return $agent;
    }

    /**
     * The agent removed the platform's access to their account.
     */
    public function disconnect(string $accountId): void
    {
        User::where('stripe_account_id', $accountId)->update([
            'stripe_charges_enabled' => false,
            'stripe_payouts_enabled' => false,
            'stripe_disabled_reason' => 'deauthorized',
            'stripe_account_updated_at' => now(),
        ]);
    }

    public function summary(User $agent): array
    {
        return [
            'status' => $agent->payoutStatus(),
            'details_submitted' => (bool) $agent->stripe_details_submitted,
            'charges_enabled' => (bool) $agent->stripe_charges_enabled,
            'payouts_enabled' => (bool) $agent->stripe_payouts_enabled,
            'requirements_due' => (int) $agent->stripe_requirements_due,
            'disabled_reason' => $agent->stripe_disabled_reason,
            'updated_at' => $agent->stripe_account_updated_at?->toIso8601String(),
            'available' => $this->stripe->isConfigured(),
        ];
    }

    private function createAccount(User $agent): string
    {
        return DB::transaction(function () use ($agent) {
            // Lock the agent so two clicks can't create two Stripe accounts.
            $locked = User::whereKey($agent->id)->lockForUpdate()->first();
            if ($locked->stripe_account_id) {
                return $locked->stripe_account_id;
            }

            $accountId = $this->stripe->createConnectAccount([
                'type' => 'express',
                'country' => 'US',
                'email' => $agent->email,
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_profile' => [
                    'mcc' => '6513', // Real estate agents and managers
                    'product_description' => 'Real estate listing services',
                ],
                // Agents withdraw with a button (phase 4), so Stripe doesn't pay out automatically
                'settings' => ['payouts' => ['schedule' => ['interval' => 'manual']]],
                'metadata' => ['user_id' => (string) $agent->id],
            ], "connect-account-{$agent->id}");

            $locked->forceFill(['stripe_account_id' => $accountId, 'stripe_account_updated_at' => now()])->save();

            return $accountId;
        });
    }
}
