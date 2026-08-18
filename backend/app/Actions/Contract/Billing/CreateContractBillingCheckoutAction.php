<?php

namespace App\Actions\Contract\Billing;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\Billing\ContractBillingStatuses;
use App\Support\Contract\Billing\Gateway\ContractBillingCheckoutData;
use App\Support\Contract\Billing\Gateway\ContractBillingCheckoutOutcome;
use App\Support\Contract\Billing\Gateway\ContractBillingCheckoutResult;
use App\Support\Contract\Billing\Gateway\ContractBillingGateway;
use App\Support\Contract\ContractStatusMachine;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one canonical entry point for starting (or safely resuming) a Stripe
 * Checkout Session that creates the Service Contract's Subscription (POST
 * /v1/contracts/{contract}/billing/checkout, BLUE V1 Phase 11). Every
 * monetary/provider value in the resulting Checkout Session comes from the
 * immutable `service_contract_billings` snapshot Admin approval already
 * froze (App\Actions\Admin\Contract\AdminApproveContractAction) - this
 * Action never accepts amount, currency, interval, a Stripe Price/Product
 * id, or a subscription status from the request; there is nothing in its
 * signature for a client to supply any of those through.
 *
 * Preconditions (all-or-nothing, checked under lock): the contract belongs
 * to the calling customer, is currently PENDING_PAYMENT (implies an
 * accepted agreement already exists - PENDING_PAYMENT is structurally only
 * reachable via App\Actions\Contract\AcceptContractAction), and its billing
 * row is still PENDING_CHECKOUT. INCOMPLETE / ACTIVE / PAST_DUE /
 * CANCEL_AT_PERIOD_END / CANCELLED are never allowed to start another
 * Checkout because a Subscription may already exist. A linked
 * stripe_subscription_id is also an explicit stop condition even if an
 * out-of-order webhook left the local status temporarily PENDING_CHECKOUT.
 *
 * Two-transaction shape, exactly mirroring
 * App\Actions\Payment\CreatePaymentAttemptAction: Transaction A locks the
 * Contract then the Billing row, validates every precondition, and either
 * resumes an existing not-yet-completed Checkout Session (zero Stripe
 * calls) or reads out a frozen ContractBillingCheckoutData snapshot -
 * before releasing every lock. The Stripe network call happens with no lock
 * held. Transaction B re-locks the Billing row and persists the result only
 * if it is still in a state where that is safe - a concurrent webhook
 * delivery may have already resolved it in the meantime, and that always
 * wins.
 *
 * Idempotency: ContractBillingCheckoutData::$providerIdempotencyKey is
 * derived solely from the Contract Billing UUID (`blue_contract_billing_
 * checkout_<uuid>`), never randomized per call - a retry that still reaches
 * the gateway (e.g. after Transaction A found no resumable session yet)
 * cannot create a second Stripe Checkout Session even if two requests race
 * past the "no existing session" read at the same time. $customerIdempotencyKey
 * is similarly derived from the customer's own user UUID, so retries can
 * never create two Stripe Customers for the same BLUE customer either -
 * see "STRIPE CUSTOMER" below.
 */
class CreateContractBillingCheckoutAction
{
    use BuildsCartResult;

    public function __construct(
        private readonly ContractBillingGateway $gateway,
        private readonly ContractStatusMachine $contractMachine = new ContractStatusMachine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $contractUuid): array
    {
        try {
            $contractIdBinary = UuidBinary::toBinary($contractUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service contract not found.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        $prepared = DB::transaction(fn (): array => $this->prepare($contractIdBinary, $userIdBinary));

        if (isset($prepared['error'])) {
            return $prepared['error'];
        }

        $result = $this->gateway->createSubscriptionCheckout($prepared['checkoutData']);

        return match ($result->outcome) {
            ContractBillingCheckoutOutcome::CREATED => $this->persistSuccess($prepared['billingIdBinary'], $userIdBinary, $result),
            ContractBillingCheckoutOutcome::DEFINITIVE_FAILURE => $this->conflict('Unable to start billing checkout. Please contact support.'),
            ContractBillingCheckoutOutcome::UNKNOWN => $this->conflict('Unable to start billing checkout right now. Please try again.'),
        };
    }

    /**
     * @return array{error: array<string, mixed>}|array{proceed: true, checkoutData: ContractBillingCheckoutData, billingIdBinary: string}
     */
    private function prepare(string $contractIdBinary, string $userIdBinary): array
    {
        $contract = DB::table('service_contracts')
            ->where('id', $contractIdBinary)
            ->where('customer_user_id', $userIdBinary)
            ->lockForUpdate()
            ->first();

        if ($contract === null) {
            return ['error' => $this->notFound('Service contract not found.')];
        }

        if (! $this->contractMachine->isInStatus($contract, 'PENDING_PAYMENT')) {
            return ['error' => $this->conflict('This contract is not awaiting billing checkout.')];
        }

        if ($contract->accepted_at === null) {
            // Structurally unreachable (PENDING_PAYMENT is only reached via
            // AcceptContractAction, which always records acceptance first)
            // but checked explicitly per BLUE V1 Phase 11's own precondition
            // list, never assumed.
            return ['error' => $this->conflict('This contract has not been accepted yet.')];
        }

        $billing = DB::table('service_contract_billings')->where('service_contract_id', $contract->id)->lockForUpdate()->first();

        if ($billing === null) {
            return ['error' => $this->conflict('This contract has no billing terms configured.')];
        }

        $billingStatusCode = ContractBillingStatuses::code((int) $billing->status_id);

        if ($billingStatusCode !== 'PENDING_CHECKOUT') {
            return ['error' => $this->conflict('This contract already has a Stripe subscription in progress or active.')];
        }

        if ($billing->stripe_subscription_id !== null) {
            // A provider webhook may link the Subscription before the local
            // Checkout Session response is persisted. Once a Subscription
            // exists, never create another Checkout Session as a recovery
            // mechanism: Stripe API v1 idempotency keys are not permanent.
            return ['error' => $this->conflict('This contract already has a Stripe subscription in progress or active.')];
        }

        if ($billing->stripe_checkout_session_id !== null) {
            // Resume the one existing not-yet-completed Checkout Session.
            // No provider call and therefore no possibility of creating a
            // second Subscription.
            return ['error' => $this->ok(200, 'Billing checkout already in progress.', [
                'billing' => [
                    'checkout_session_id' => $billing->stripe_checkout_session_id,
                    'checkout_url' => $billing->stripe_checkout_url,
                ],
            ])];
        }

        $customerProfile = DB::table('customer_profiles')->where('user_id', $userIdBinary)->first(['stripe_customer_id']);
        $user = DB::table('users')->where('id', $userIdBinary)->first(['email']);
        $currency = DB::table('currencies')->where('id', $billing->currency_id)->first(['code', 'minor_unit']);

        if ($user === null || $currency === null) {
            return ['error' => $this->conflict('This contract could not be prepared for billing checkout.')];
        }

        $checkoutData = new ContractBillingCheckoutData(
            contractBillingUuid: UuidBinary::toString($billing->id),
            contractUuid: UuidBinary::toString($contract->id),
            stripeCustomerId: $customerProfile->stripe_customer_id ?? null,
            customerEmail: $user->email,
            recurringAmount: $billing->recurring_amount,
            currencyCode: $currency->code,
            currencyMinorUnit: (int) $currency->minor_unit,
            billingInterval: $billing->billing_interval,
            productName: 'Service Contract '.$contract->contract_number,
            successUrl: (string) config('contract_billing.checkout_success_url'),
            cancelUrl: (string) config('contract_billing.checkout_cancel_url'),
            providerIdempotencyKey: 'blue_contract_billing_checkout_'.UuidBinary::toString($billing->id),
            customerIdempotencyKey: 'blue_contract_billing_customer_'.UuidBinary::toString($userIdBinary),
        );

        return ['proceed' => true, 'checkoutData' => $checkoutData, 'billingIdBinary' => $billing->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistSuccess(string $billingIdBinary, string $userIdBinary, ContractBillingCheckoutResult $result): array
    {
        DB::transaction(function () use ($billingIdBinary, $userIdBinary, $result): void {
            $locked = DB::table('service_contract_billings')->where('id', $billingIdBinary)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $statusCode = ContractBillingStatuses::code((int) $locked->status_id);

            if (! in_array($statusCode, ['PENDING_CHECKOUT', 'INCOMPLETE'], true)) {
                // A concurrent webhook delivery already progressed this
                // billing record past checkout while the Stripe call above
                // was in flight - that always wins, never regressed here.
                return;
            }

            $timestamp = now()->format('Y-m-d H:i:s.u');

            DB::table('service_contract_billings')->where('id', $billingIdBinary)->update([
                'stripe_customer_id' => $result->stripeCustomerId,
                'stripe_checkout_session_id' => $result->checkoutSessionId,
                'stripe_checkout_url' => $result->checkoutUrl,
                'updated_at' => $timestamp,
            ]);

            // One Stripe Customer per BLUE customer, reused across every
            // future Contract - see BLUE V1 Phase 11 "STRIPE CUSTOMER".
            // Only backfilled when still unset; never overwritten.
            DB::table('customer_profiles')
                ->where('user_id', $userIdBinary)
                ->whereNull('stripe_customer_id')
                ->update(['stripe_customer_id' => $result->stripeCustomerId, 'updated_at' => $timestamp]);
        });

        $fresh = DB::table('service_contract_billings')->where('id', $billingIdBinary)->first(['stripe_checkout_session_id', 'stripe_checkout_url']);

        return $this->ok(201, 'Billing checkout session created.', [
            'billing' => [
                'checkout_session_id' => $fresh->stripe_checkout_session_id,
                'checkout_url' => $fresh->stripe_checkout_url,
            ],
        ]);
    }
}
