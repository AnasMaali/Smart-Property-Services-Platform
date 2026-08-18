<?php

namespace App\Actions\Contract\Billing;

use App\Support\Contract\Billing\ContractBillingStatuses;
use App\Support\Contract\Billing\Gateway\ContractBillingGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Best-effort, durable-by-construction request to schedule a Contract's
 * Stripe Subscription to automatically cancel at the Contract's own
 * `ends_at` (BLUE V1 Phase 11 real-Stripe-test-mode fix). Enforces the
 * business guarantee that a Contract's subscription must never continue
 * billing past its own term end.
 *
 * WHY this exists as a separate, post-creation step: a real Stripe
 * test-mode call proved `checkout.sessions.create()` rejects a
 * `subscription_data.cancel_at` parameter outright (400
 * `invalid_request_error` / `parameter_unknown` - "Received unknown
 * parameter: subscription_data[cancel_at]"), confirmed against the
 * installed stripe-php SDK's own type contract for that call - Checkout
 * Session creation has no such field. `Subscription::update()` DOES accept
 * `cancel_at` (same SDK contract), so this Action calls
 * ContractBillingGateway::scheduleSubscriptionCancelAt() instead, the
 * moment a Subscription id is first known - see
 * App\Actions\Contract\Billing\ProcessContractBillingWebhookAction, which
 * calls this AFTER its own DB transaction commits (never inside it - a
 * provider outage must never roll back webhook processing), from BOTH
 * checkout.session.completed and customer.subscription.created/updated -
 * whichever webhook arrives first (webhook-order-safe: either event can
 * legitimately be delivered before the other).
 *
 * Idempotent / retry-safe WITHOUT any new durable-marker column - unlike
 * App\Actions\Contract\Billing\CancelContractBillingSubscriptionAction's
 * cancellation-request tracking (which genuinely needs one, since a
 * Contract already reads CANCELLED locally the instant it is cancelled,
 * making "was the provider request ever sent" otherwise unrecoverable from
 * local state alone). Here, "still pending" is fully and durably derivable
 * from already-existing columns: a Subscription id is known
 * (`stripe_subscription_id` is set) but `cancel_at` is not yet confirmed
 * (`service_contract_billings.cancel_at IS NULL`). Stripe's own
 * `Subscription::update()` is itself safe to call repeatedly with the same
 * value, so App\Actions\Contract\Billing\
 * RetryPendingContractBillingCancelAtSchedulingAction can simply keep
 * calling this Action for any row matching that condition, for as long as
 * needed, with no risk of double-scheduling or drift.
 *
 * Never writes `service_contract_billings` itself - only the
 * customer.subscription.updated webhook that this very call triggers
 * reports the new `cancel_at` back, which
 * ProcessContractBillingWebhookAction's existing, unmodified
 * handleSubscriptionSync() already records - so this side channel can
 * never race or conflict with that webhook-authoritative write.
 */
class ScheduleContractBillingSubscriptionCancelAtAction
{
    public function __construct(private readonly ContractBillingGateway $gateway) {}

    public function handle(string $contractIdBinary): void
    {
        $billing = DB::table('service_contract_billings')
            ->where('service_contract_id', $contractIdBinary)
            ->first(['status_id', 'stripe_subscription_id', 'cancel_at']);

        if ($billing === null || $billing->stripe_subscription_id === null || $billing->cancel_at !== null) {
            return;
        }

        if (ContractBillingStatuses::code((int) $billing->status_id) === 'CANCELLED') {
            return;
        }

        $endsAt = DB::table('service_contracts')->where('id', $contractIdBinary)->value('ends_at');

        if ($endsAt === null) {
            return;
        }

        try {
            $this->gateway->scheduleSubscriptionCancelAt($billing->stripe_subscription_id, Carbon::parse($endsAt)->getTimestamp());
        } catch (Throwable $e) {
            // At-least-once delivery, never exactly-once - see interface
            // docblock. A failed/ambiguous attempt must never bubble up;
            // contracts:retry-pending-cancel-at-scheduling keeps retrying
            // for as long as `cancel_at` stays unconfirmed locally.
            report($e);
        }
    }
}
