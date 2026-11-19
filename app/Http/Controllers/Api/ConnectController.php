<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ConnectService;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Throwable;

/**
 * Agent payout account (Stripe Connect Express): status, onboarding, dashboard, and the
 * Connect webhook. The account is always the logged-in agent's own; IDs never come from the client.
 */
class ConnectController extends Controller
{
    public function __construct(
        private readonly ConnectService $connect,
        private readonly StripeGateway $stripe,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $agent = $request->user()->fresh();

        // ?refresh=1 (e.g. back from onboarding) re-reads the account from Stripe
        if ($request->boolean('refresh') && $agent->stripe_account_id && $this->stripe->isConfigured()) {
            try {
                $agent = $this->connect->refresh($agent);
            } catch (Throwable $e) {
                Log::warning('Stripe account refresh failed', ['user_id' => $agent->id, 'error' => $e->getMessage()]);
            }
        }

        return api_success($this->connect->summary($agent));
    }

    public function onboarding(Request $request): JsonResponse
    {
        return $this->stripeLink(fn () => $this->connect->onboardingLink($request->user()->fresh()));
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->stripeLink(fn () => $this->connect->dashboardLink($request->user()->fresh()));
    }

    /**
     * Webhook for events from connected accounts. Trusted only after the signature check.
     */
    public function webhook(Request $request): JsonResponse
    {
        try {
            $event = $this->stripe->constructConnectWebhookEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', '')
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        } catch (\RuntimeException $e) {
            Log::error('Stripe Connect webhook not configured', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook not configured.'], 500);
        }

        switch ($event->type) {
            case 'account.updated':
                $this->connect->apply(StripeGateway::accountToArray($event->data->object));
                break;
            case 'account.application.deauthorized':
                if ($event->account) {
                    $this->connect->disconnect($event->account);
                }
                break;
            case 'payout.created':
            case 'payout.updated':
            case 'payout.paid':
            case 'payout.failed':
            case 'payout.canceled':
                $payout = $event->data->object;
                app(\App\Services\PayoutService::class)->applyPayoutEvent([
                    'id' => $payout->id,
                    'status' => $payout->status ?? null,
                    'failure_message' => $payout->failure_message ?? null,
                    'arrival_date' => $payout->arrival_date ?? null,
                ], $event->account ?? null);
                break;
        }

        return response()->json(['received' => true]);
    }

    private function stripeLink(callable $make): JsonResponse
    {
        if (! $this->stripe->isConfigured()) {
            return api_error('Payouts are not available yet. Please contact support.', 503);
        }

        try {
            return api_success(['url' => $make()]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe Connect link failed', ['error' => $e->getMessage()]);

            return api_error('Could not open Stripe right now. Please try again.', 502);
        }
    }
}
