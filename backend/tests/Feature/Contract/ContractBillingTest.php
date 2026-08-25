<?php

namespace Tests\Feature\Contract;

use App\Actions\Contract\Billing\RetryPendingContractBillingCancelAtSchedulingAction;
use App\Actions\Contract\Billing\RetryPendingContractBillingCancellationsAction;
use App\Actions\Contract\Billing\SuspendContractsPastDueBillingAction;
use App\Support\Contract\Billing\Gateway\ContractBillingCheckoutResult;
use App\Support\Contract\ContractStatuses;
use App\Support\Uuid\UuidBinary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Contract\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * BLUE V1 Phase 11 - Service Contract Stripe Billing (subscription
 * Checkout + webhook activation/renewal/cancellation). Every test here
 * runs against App\Support\Contract\Billing\Gateway\FakeContractBillingGateway
 * (App\Providers\ContractBillingServiceProvider binds it under "testing"),
 * so no test ever reaches the real Stripe network.
 */
class ContractBillingTest extends TestCase
{
    use CreatesContractFixtures;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutFixtures();
    }

    private function webhookLedgerRow(string $providerEventId): ?object
    {
        return DB::table('service_contract_billing_webhook_events')->where('provider_event_id', $providerEventId)->first();
    }

    private function webhookLedgerStatusCode(string $providerEventId): ?string
    {
        return DB::table('service_contract_billing_webhook_events')
            ->join('payment_webhook_event_statuses', 'payment_webhook_event_statuses.id', '=', 'service_contract_billing_webhook_events.status_id')
            ->where('service_contract_billing_webhook_events.provider_event_id', $providerEventId)
            ->value('payment_webhook_event_statuses.code');
    }

    private function statusHistoryCount(string $contractUuid, string $toStatusCode): int
    {
        return DB::table('service_contract_status_history')
            ->join('service_contract_statuses', 'service_contract_statuses.id', '=', 'service_contract_status_history.to_status_id')
            ->where('service_contract_status_history.service_contract_id', UuidBinary::toBinary($contractUuid))
            ->where('service_contract_statuses.code', $toStatusCode)
            ->count();
    }

    private function statusTransitionCount(string $contractUuid, string $fromStatusCode, string $toStatusCode): int
    {
        return DB::table('service_contract_status_history')
            ->join('service_contract_statuses as from_status', 'from_status.id', '=', 'service_contract_status_history.from_status_id')
            ->join('service_contract_statuses as to_status', 'to_status.id', '=', 'service_contract_status_history.to_status_id')
            ->where('service_contract_status_history.service_contract_id', UuidBinary::toBinary($contractUuid))
            ->where('from_status.code', $fromStatusCode)
            ->where('to_status.code', $toStatusCode)
            ->count();
    }

    /**
     * @return array{customer: array, admin: array, service: array, contract_uuid: string}
     */
    private function pendingPaymentContract(array $approveOverrides = []): array
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $contractUuid = $created->json('data.contract.uuid');
        $admin = $this->createAndLoginAdmin();

        $this->adminApproveContract($admin['access_token'], $contractUuid, $this->approveContractPayload($service['uuid'], $approveOverrides))->assertStatus(200);
        $this->adminSendContractForAcceptance($admin['access_token'], $contractUuid)->assertStatus(200);
        $this->acceptContractHttp($customer['access_token'], $contractUuid)->assertStatus(200);

        return ['customer' => $customer, 'admin' => $admin, 'service' => $service, 'contract_uuid' => $contractUuid];
    }

    public function test_monthly_approval_creates_pending_checkout_billing_row(): void
    {
        $ctx = $this->pendingPaymentContract(['billing_interval' => 'MONTHLY', 'recurring_amount' => '75.500000', 'billing_currency_code' => 'AED']);

        $billing = $this->billingRow($ctx['contract_uuid']);

        $this->assertNotNull($billing);
        $this->assertSame('MONTHLY', $billing->billing_interval);
        $this->assertSame('75.500000', $billing->recurring_amount);
        $this->assertSame('PENDING_CHECKOUT', $this->billingStatusCode($ctx['contract_uuid']));
    }

    public function test_yearly_approval_creates_pending_checkout_billing_row(): void
    {
        $ctx = $this->pendingPaymentContract(['billing_interval' => 'YEARLY', 'recurring_amount' => '900.000000', 'billing_currency_code' => 'AED']);

        $billing = $this->billingRow($ctx['contract_uuid']);

        $this->assertNotNull($billing);
        $this->assertSame('YEARLY', $billing->billing_interval);
        $this->assertSame('900.000000', $billing->recurring_amount);
    }

    public function test_approve_rejects_invalid_billing_interval(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);

        $admin = $this->createAndLoginAdmin();

        $response = $this->adminApproveContract(
            $admin['access_token'],
            $created->json('data.contract.uuid'),
            $this->approveContractPayload($service['uuid'], ['billing_interval' => 'WEEKLY'])
        );

        $response->assertStatus(422);
    }

    public function test_accept_transitions_to_pending_payment_not_active(): void
    {
        $ctx = $this->pendingPaymentContract();

        $contract = $this->contractRow($ctx['contract_uuid']);

        $this->assertSame('PENDING_PAYMENT', ContractStatuses::code((int) $contract->status_id));
        $this->assertSame('PENDING_CHECKOUT', $this->billingStatusCode($ctx['contract_uuid']));
    }

    public function test_customer_cannot_influence_checkout_amount_currency_or_interval(): void
    {
        $ctx = $this->pendingPaymentContract(['billing_interval' => 'MONTHLY', 'recurring_amount' => '42.000000', 'billing_currency_code' => 'AED']);

        // The checkout endpoint accepts no request body at all - anything
        // the customer posts must have zero effect on the resulting
        // provider-side Checkout Session.
        $response = $this->postJson('/api/v1/contracts/'.$ctx['contract_uuid'].'/billing/checkout', [
            'recurring_amount' => '1.000000',
            'billing_interval' => 'YEARLY',
            'currency_code' => 'USD',
            'stripe_price_id' => 'price_hacked',
        ], ['Authorization' => 'Bearer '.$ctx['customer']['access_token']]);

        $response->assertStatus(201);

        $call = $this->fakeBillingGateway()->createCheckoutCalls[0];
        $this->assertSame('42.000000', $call->recurringAmount);
        $this->assertSame('MONTHLY', $call->billingInterval);
        $this->assertSame('AED', $call->currencyCode);
    }

    public function test_fake_gateway_receives_server_authoritative_billing_terms(): void
    {
        $ctx = $this->pendingPaymentContract(['billing_interval' => 'YEARLY', 'recurring_amount' => '600.000000', 'billing_currency_code' => 'AED']);

        $this->contractBillingCheckoutHttp($ctx['customer']['access_token'], $ctx['contract_uuid'])->assertStatus(201);

        $call = $this->fakeBillingGateway()->createCheckoutCalls[0];
        $this->assertSame($ctx['contract_uuid'], $call->contractUuid);
        $this->assertSame('YEARLY', $call->billingInterval);
        $this->assertSame('600.000000', $call->recurringAmount);
        $this->assertSame('AED', $call->currencyCode);
    }

    /**
     * BLUE V1 Phase 11 real-Stripe-test-mode fix: `checkout.sessions.create()`
     * has no `subscription_data.cancel_at` parameter (verified against a
     * real Stripe test-mode 400 `parameter_unknown` response and the
     * installed stripe-php SDK's own type contract), so the Checkout call
     * itself carries no cancellation timestamp - see
     * App\Actions\Contract\Billing\ScheduleContractBillingSubscriptionCancelAtAction
     * for where the "never bill past ends_at" guarantee is actually enforced.
     */
    public function test_checkout_call_never_sends_a_cancel_at_parameter(): void
    {
        $ctx = $this->pendingPaymentContract();

        $this->contractBillingCheckoutHttp($ctx['customer']['access_token'], $ctx['contract_uuid'])->assertStatus(201);

        $call = $this->fakeBillingGateway()->createCheckoutCalls[0];
        $this->assertFalse(property_exists($call, 'cancelAtUnixTimestamp'));
    }

    /**
     * Requirement (business guarantee): the Stripe subscription must never
     * continue billing past the Contract's own `ends_at`. Enforced
     * post-creation via ContractBillingGateway::scheduleSubscriptionCancelAt(),
     * fired the moment the Subscription id first becomes known - here, from
     * checkout.session.completed (the earliest point activateContractBilling()
     * links a subscription id).
     */
    public function test_checkout_completion_schedules_provider_subscription_cancel_at_term_end(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;
        $expectedTimestamp = Carbon::parse($built['contract']->ends_at)->getTimestamp();

        $this->assertSame([[$subscriptionId, $expectedTimestamp]], $this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls);

        // Never self-written by the scheduling attempt - only the eventual
        // customer.subscription.updated webhook confirms it.
        $this->assertNull($this->billingRow($contractUuid)->cancel_at);
    }

    /**
     * Once the provider webhook confirms `cancel_at` locally, a further
     * subscription-sync event must never trigger a redundant scheduling
     * attempt.
     */
    public function test_confirmed_cancel_at_is_never_rescheduled(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;
        $expectedTimestamp = Carbon::parse($built['contract']->ends_at)->getTimestamp();

        $this->assertCount(1, $this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls);

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_cancel_at_confirmed_'.UuidBinary::generate(),
            'event_type' => 'customer.subscription.updated',
            'stripe_subscription_id' => $subscriptionId,
            'subscription_status' => 'active',
            'cancel_at' => now()->setTimestamp($expectedTimestamp)->toDateTimeString(),
        ]))->assertStatus(200);

        $this->assertNotNull($this->billingRow($contractUuid)->cancel_at);

        // A later, unrelated subscription-sync event must not re-trigger
        // scheduling now that cancel_at is confirmed.
        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_unrelated_sync_'.UuidBinary::generate(),
            'event_type' => 'customer.subscription.updated',
            'stripe_subscription_id' => $subscriptionId,
            'subscription_status' => 'active',
        ]))->assertStatus(200);

        $this->assertCount(1, $this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls);
    }

    /**
     * Webhook-order-safety: customer.subscription.created can legitimately
     * be delivered before checkout.session.completed - the schedule attempt
     * must still fire from whichever event links the subscription id first.
     */
    public function test_subscription_created_before_checkout_completed_still_schedules_cancel_at(): void
    {
        $ctx = $this->pendingPaymentContract();
        $contract = $this->contractRow($ctx['contract_uuid']);
        $checkout = $this->contractBillingCheckoutHttp($ctx['customer']['access_token'], $ctx['contract_uuid'])->assertStatus(201);
        $subscriptionId = 'sub_out_of_order_'.UuidBinary::generate();

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_sub_created_first_'.UuidBinary::generate(),
            'event_type' => 'customer.subscription.created',
            'contract_billing_uuid' => UuidBinary::toString($this->billingRow($ctx['contract_uuid'])->id),
            'stripe_subscription_id' => $subscriptionId,
            'subscription_status' => 'incomplete',
        ]))->assertStatus(200);

        $expectedTimestamp = Carbon::parse($contract->ends_at)->getTimestamp();
        $this->assertSame([[$subscriptionId, $expectedTimestamp]], $this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls);

        // The later checkout.session.completed for the same session must
        // never re-trigger scheduling (subscription id already known,
        // cancel_at still unconfirmed either way - only one attempt so far).
        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_checkout_completed_after_'.UuidBinary::generate(),
            'event_type' => 'checkout.session.completed',
            'stripe_checkout_session_id' => $checkout->json('data.billing.checkout_session_id'),
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);

        $this->assertCount(1, $this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls);
    }

    /**
     * A failed/ambiguous provider delivery attempt must never lose the
     * scheduling request - contracts:retry-pending-cancel-at-scheduling
     * retries it later.
     */
    public function test_retry_command_reattempts_cancel_at_scheduling_after_a_prior_provider_failure(): void
    {
        $this->fakeBillingGateway()->queueScheduleCancelAtFailure(new \RuntimeException('simulated provider outage'));

        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;
        $expectedTimestamp = Carbon::parse($built['contract']->ends_at)->getTimestamp();

        $this->assertSame([[$subscriptionId, $expectedTimestamp]], $this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls);
        $this->assertNull($this->billingRow($contractUuid)->cancel_at);

        $retried = app(RetryPendingContractBillingCancelAtSchedulingAction::class)->handle();

        $this->assertSame(1, $retried);
        $this->assertSame(
            [[$subscriptionId, $expectedTimestamp], [$subscriptionId, $expectedTimestamp]],
            $this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls
        );
    }

    public function test_retry_pending_cancel_at_scheduling_command_is_safe_no_op_once_cancel_at_is_confirmed(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;
        $expectedTimestamp = Carbon::parse($built['contract']->ends_at)->getTimestamp();

        // Nothing pending yet is also safe (webhook confirmation just
        // hasn't arrived) - the retry command re-sends the same attempt,
        // itself a safe no-op provider-side.
        $retried = app(RetryPendingContractBillingCancelAtSchedulingAction::class)->handle();
        $this->assertSame(1, $retried);

        // Once the webhook confirms cancel_at, the row is durably excluded
        // from every future run - a genuine no-op, not just a repeat call.
        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_cancel_at_confirmed_'.UuidBinary::generate(),
            'event_type' => 'customer.subscription.updated',
            'stripe_subscription_id' => $subscriptionId,
            'subscription_status' => 'active',
            'cancel_at' => now()->setTimestamp($expectedTimestamp)->toDateTimeString(),
        ]))->assertStatus(200);

        $callsBeforeConfirmedRetry = count($this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls);

        $retriedAfterConfirmation = app(RetryPendingContractBillingCancelAtSchedulingAction::class)->handle();
        $this->assertSame(0, $retriedAfterConfirmation);

        $retriedAgain = app(RetryPendingContractBillingCancelAtSchedulingAction::class)->handle();
        $this->assertSame(0, $retriedAgain);

        $this->assertCount($callsBeforeConfirmedRetry, $this->fakeBillingGateway()->scheduleSubscriptionCancelAtCalls);
    }

    public function test_checkout_rejected_before_contract_reaches_pending_payment(): void
    {
        $customer = $this->createAuthenticatedCartCustomer();
        $property = $this->createProperty($customer['access_token']);
        $service = $this->createSubscriptionEligibleService();

        $created = $this->requestContract($customer['access_token'], [
            'property_uuid' => $property['uuid'],
            'all_services' => false,
            'service_uuids' => [$service['uuid']],
        ])->assertStatus(201);
        // Still REQUESTED - never approved/accepted.

        $response = $this->contractBillingCheckoutHttp($customer['access_token'], $created->json('data.contract.uuid'));

        $response->assertStatus(409);
    }

    public function test_checkout_rejected_once_billing_already_active(): void
    {
        $built = $this->activeContractWithItem();

        $response = $this->contractBillingCheckoutHttp($built['customer']['access_token'], UuidBinary::toString($built['contract']->id));

        $response->assertStatus(409);
    }

    public function test_foreign_customer_cannot_start_checkout_for_another_customers_contract(): void
    {
        $ctx = $this->pendingPaymentContract();
        $stranger = $this->createAuthenticatedCartCustomer();

        $response = $this->contractBillingCheckoutHttp($stranger['access_token'], $ctx['contract_uuid']);

        $response->assertStatus(404);
    }

    public function test_checkout_is_idempotent_and_never_calls_gateway_twice_while_pending(): void
    {
        $ctx = $this->pendingPaymentContract();

        $first = $this->contractBillingCheckoutHttp($ctx['customer']['access_token'], $ctx['contract_uuid'])->assertStatus(201);
        $second = $this->contractBillingCheckoutHttp($ctx['customer']['access_token'], $ctx['contract_uuid'])->assertStatus(200);

        $this->assertSame(
            $first->json('data.billing.checkout_session_id'),
            $second->json('data.billing.checkout_session_id')
        );
        $this->assertCount(1, $this->fakeBillingGateway()->createCheckoutCalls);
    }

    /**
     * Once the local billing lifecycle has reached INCOMPLETE, a provider
     * Subscription may already exist. A later customer retry must therefore
     * never call the provider to create another Checkout Session, even when
     * no local checkout_session_id is available to resume.
     */
    public function test_incomplete_billing_can_never_start_a_second_checkout(): void
    {
        $ctx = $this->pendingPaymentContract();
        $billing = $this->billingRow($ctx['contract_uuid']);

        $incompleteStatusId = (int) DB::table('service_contract_billing_statuses')
            ->where('code', 'INCOMPLETE')
            ->value('id');

        DB::table('service_contract_billings')
            ->where('id', $billing->id)
            ->update([
                'status_id' => $incompleteStatusId,
                'stripe_checkout_session_id' => null,
                'stripe_checkout_url' => null,
                'updated_at' => now()->format('Y-m-d H:i:s.u'),
            ]);

        $this->assertCount(0, $this->fakeBillingGateway()->createCheckoutCalls);

        $this->contractBillingCheckoutHttp(
            $ctx['customer']['access_token'],
            $ctx['contract_uuid']
        )->assertStatus(409);

        $this->assertCount(0, $this->fakeBillingGateway()->createCheckoutCalls);
        $this->assertSame('INCOMPLETE', $this->billingStatusCode($ctx['contract_uuid']));
    }

    /**
     * Webhook-order safety: a Subscription id may become known while the
     * local row still reads PENDING_CHECKOUT. The presence of that provider
     * Subscription id is itself sufficient proof that creating a new
     * Checkout Session would risk creating a duplicate Subscription.
     */
    public function test_pending_checkout_with_linked_subscription_can_never_start_another_checkout(): void
    {
        $ctx = $this->pendingPaymentContract();
        $billing = $this->billingRow($ctx['contract_uuid']);
        $subscriptionId = 'sub_already_linked_'.UuidBinary::generate();

        DB::table('service_contract_billings')
            ->where('id', $billing->id)
            ->update([
                'stripe_subscription_id' => $subscriptionId,
                'updated_at' => now()->format('Y-m-d H:i:s.u'),
            ]);

        $this->assertSame('PENDING_CHECKOUT', $this->billingStatusCode($ctx['contract_uuid']));
        $this->assertCount(0, $this->fakeBillingGateway()->createCheckoutCalls);

        $this->contractBillingCheckoutHttp(
            $ctx['customer']['access_token'],
            $ctx['contract_uuid']
        )->assertStatus(409);

        $this->assertCount(0, $this->fakeBillingGateway()->createCheckoutCalls);
        $this->assertSame(
            $subscriptionId,
            $this->billingRow($ctx['contract_uuid'])->stripe_subscription_id
        );
    }

    public function test_definitive_checkout_failure_leaves_billing_row_untouched(): void
    {
        $ctx = $this->pendingPaymentContract();

        $this->fakeBillingGateway()->alwaysReturnCheckout(ContractBillingCheckoutResult::definitiveFailure('STRIPE_REQUEST_REJECTED', 'boom'));

        $response = $this->contractBillingCheckoutHttp($ctx['customer']['access_token'], $ctx['contract_uuid']);

        $response->assertStatus(409);
        $billing = $this->billingRow($ctx['contract_uuid']);
        $this->assertNull($billing->stripe_checkout_session_id);
        $this->assertSame('PENDING_CHECKOUT', $this->billingStatusCode($ctx['contract_uuid']));
    }

    public function test_successful_initial_invoice_activates_contract_and_billing_exactly_once(): void
    {
        $ctx = $this->pendingPaymentContract();
        $result = $this->activateContractBilling($ctx['customer']['access_token'], $ctx['contract_uuid']);

        $contract = $this->contractRow($ctx['contract_uuid']);
        $this->assertSame('ACTIVE', ContractStatuses::code((int) $contract->status_id));
        $this->assertSame('ACTIVE', $this->billingStatusCode($ctx['contract_uuid']));
        $this->assertSame(1, $this->statusHistoryCount($ctx['contract_uuid'], 'ACTIVE'));

        // A later renewal invoice.paid for the same subscription must never
        // write a second Contract lifecycle history row.
        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_renewal_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $result['stripe_subscription_id'],
            'invoice_paid' => true,
        ]))->assertStatus(200);

        $this->assertSame(1, $this->statusHistoryCount($ctx['contract_uuid'], 'ACTIVE'));
    }

    public function test_duplicate_webhook_delivery_is_idempotent(): void
    {
        $ctx = $this->pendingPaymentContract();
        $checkout = $this->contractBillingCheckoutHttp($ctx['customer']['access_token'], $ctx['contract_uuid'])->assertStatus(201);
        $subscriptionId = 'sub_test_'.UuidBinary::generate();
        $eventId = 'evt_dup_'.UuidBinary::generate();

        $payload = $this->fakeContractBillingWebhookPayload([
            'event_id' => $eventId,
            'event_type' => 'checkout.session.completed',
            'stripe_checkout_session_id' => $checkout->json('data.billing.checkout_session_id'),
            'stripe_subscription_id' => $subscriptionId,
        ]);

        $this->postContractBillingWebhook($payload)->assertStatus(200);
        $this->postContractBillingWebhook($payload)->assertStatus(200);

        $this->assertSame(1, DB::table('service_contract_billing_webhook_events')->where('provider_event_id', $eventId)->count());
        $this->assertSame('INCOMPLETE', $this->billingStatusCode($ctx['contract_uuid']));
    }

    public function test_invalid_webhook_signature_mutates_nothing(): void
    {
        $ctx = $this->pendingPaymentContract();

        $response = $this->postContractBillingWebhook(
            $this->fakeContractBillingWebhookPayload(['event_type' => 'invoice.paid']),
            signatureOverride: 'not-the-right-signature'
        );

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('service_contract_billing_webhook_events')->count());
        $this->assertSame('PENDING_CHECKOUT', $this->billingStatusCode($ctx['contract_uuid']));
    }

    /**
     * Real-Stripe-test-mode-discovered bug: Stripe does not guarantee
     * webhook delivery order, and a real live test run observed
     * `invoice.paid` delivered BEFORE `checkout.session.completed` /
     * `customer.subscription.created` had linked the Subscription id
     * locally - `resolveBilling()` has no other way to match an invoice
     * event (fromInvoice() never carries a contractBillingUuid or checkout
     * session id), so this used to be silently marked FAILED
     * (BILLING_RECORD_NOT_FOUND) while still returning HTTP 200 - telling
     * Stripe delivery succeeded and permanently stranding activation, since
     * this codebase never persists the raw webhook body to self-replay it.
     * The fix: return non-2xx for this specific unresolvable case so
     * Stripe's own automatic webhook retry redelivers the exact same event
     * once local linkage has caught up.
     */
    public function test_out_of_order_invoice_paid_before_subscription_linkage_is_retried_by_provider(): void
    {
        $ctx = $this->pendingPaymentContract();
        $checkout = $this->contractBillingCheckoutHttp($ctx['customer']['access_token'], $ctx['contract_uuid'])->assertStatus(201);
        $subscriptionId = 'sub_out_of_order_invoice_'.UuidBinary::generate();
        $invoicePaidEventId = 'evt_invoice_paid_early_'.UuidBinary::generate();

        // invoice.paid arrives first - the Subscription id is not linked to
        // any billing row locally yet.
        $prematureInvoicePaid = $this->fakeContractBillingWebhookPayload([
            'event_id' => $invoicePaidEventId,
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]);
        $this->postContractBillingWebhook($prematureInvoicePaid)->assertStatus(409);

        $this->assertSame('FAILED', $this->webhookLedgerStatusCode($invoicePaidEventId));
        $this->assertSame('PENDING_CHECKOUT', $this->billingStatusCode($ctx['contract_uuid']));
        $this->assertSame('PENDING_PAYMENT', ContractStatuses::code((int) $this->contractRow($ctx['contract_uuid'])->status_id));

        // checkout.session.completed now links the Subscription id.
        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_checkout_completed_'.UuidBinary::generate(),
            'event_type' => 'checkout.session.completed',
            'stripe_checkout_session_id' => $checkout->json('data.billing.checkout_session_id'),
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);

        // Stripe's own automatic retry redelivers the EXACT SAME invoice.paid
        // event (same event id) - must now succeed and activate.
        $this->postContractBillingWebhook($prematureInvoicePaid)->assertStatus(200);

        $this->assertSame('PROCESSED', $this->webhookLedgerStatusCode($invoicePaidEventId));
        $this->assertSame('ACTIVE', $this->billingStatusCode($ctx['contract_uuid']));
        $this->assertSame('ACTIVE', ContractStatuses::code((int) $this->contractRow($ctx['contract_uuid'])->status_id));
        $this->assertSame(1, DB::table('service_contract_billing_webhook_events')->where('provider_event_id', $invoicePaidEventId)->count());
    }

    public function test_out_of_order_invoice_paid_cannot_resurrect_cancelled_billing(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_deleted_'.UuidBinary::generate(),
            'event_type' => 'customer.subscription.deleted',
            'stripe_subscription_id' => $subscriptionId,
            'subscription_status' => 'canceled',
            'canceled_at' => now()->toDateTimeString(),
        ]))->assertStatus(200);

        $this->assertSame('CANCELLED', $this->billingStatusCode($contractUuid));

        // A stale, late-arriving invoice.paid for the same subscription
        // must never resurrect a terminal, CANCELLED billing row.
        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_stale_paid_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]))->assertStatus(200);

        $this->assertSame('CANCELLED', $this->billingStatusCode($contractUuid));
    }

    public function test_invoice_payment_failed_transitions_billing_to_past_due(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_failed_'.UuidBinary::generate(),
            'event_type' => 'invoice.payment_failed',
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);

        $billing = $this->billingRow($contractUuid);
        $this->assertSame('PAST_DUE', $this->billingStatusCode($contractUuid));
        $this->assertNotNull($billing->past_due_since);
    }

    public function test_past_due_billing_blocks_new_contract_bookings(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_failed_'.UuidBinary::generate(),
            'event_type' => 'invoice.payment_failed',
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);

        $slot = $this->createAppointmentSlot();

        $response = $this->bookContractService(
            $built['customer']['access_token'],
            $contractUuid,
            UuidBinary::toString($built['item']->id),
            $slot['uuid']
        );

        $response->assertStatus(409);
        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    public function test_later_successful_invoice_restores_billing_eligibility_after_past_due(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_failed_'.UuidBinary::generate(),
            'event_type' => 'invoice.payment_failed',
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);
        $this->assertSame('PAST_DUE', $this->billingStatusCode($contractUuid));

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_recovered_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]))->assertStatus(200);

        $this->assertSame('ACTIVE', $this->billingStatusCode($contractUuid));
        $this->assertNull($this->billingRow($contractUuid)->past_due_since);

        $slot = $this->createAppointmentSlot();
        $response = $this->bookContractService(
            $built['customer']['access_token'],
            $contractUuid,
            UuidBinary::toString($built['item']->id),
            $slot['uuid']
        );

        $response->assertStatus(201);
    }

    /**
     * Audit point A: a Contract escalated ACTIVE -> SUSPENDED by
     * App\Actions\Contract\Billing\SuspendContractsPastDueBillingAction
     * (billing stuck PAST_DUE beyond the grace period) must have its
     * BILLING row restored to ACTIVE if Stripe's automatic retry eventually
     * succeeds - never left permanently stuck PAST_DUE. Because THIS
     * suspension is billing-caused (`billing_suspended_at` was stamped by
     * SuspendContractsPastDueBillingAction), the Contract itself is also
     * automatically recovered SUSPENDED -> ACTIVE by
     * App\Actions\Contract\Billing\ProcessContractBillingWebhookAction /
     * App\Support\Contract\ContractStatusMachine::
     * recoverSuspendedByBillingToActive() - BLUE V1 Phase 11 billing
     * suspension recovery. See
     * test_manually_suspended_contract_is_never_auto_recovered_by_billing_repair()
     * for the contrasting manual-suspension case, which is never
     * auto-recovered.
     */
    public function test_invoice_paid_restores_billing_active_after_grace_period_suspension(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_failed_'.UuidBinary::generate(),
            'event_type' => 'invoice.payment_failed',
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);
        $this->assertSame('PAST_DUE', $this->billingStatusCode($contractUuid));

        // Simulate the configured grace period having fully elapsed (rather
        // than backdating past_due_since, which would violate
        // chk_service_contract_billings_past_due_since - it can never
        // precede the row's own created_at).
        config(['contract_billing.grace_days' => 0]);

        $suspended = app(SuspendContractsPastDueBillingAction::class)->handle();
        $this->assertSame(1, $suspended);
        $this->assertSame('SUSPENDED', ContractStatuses::code((int) $this->contractRow($contractUuid)->status_id));

        // Stripe's automatic retry eventually succeeds.
        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_recovered_after_suspend_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]))->assertStatus(200);

        $this->assertSame('ACTIVE', $this->billingStatusCode($contractUuid));
        $this->assertNull($this->billingRow($contractUuid)->past_due_since);

        // This suspension was billing-caused, so the Contract is
        // automatically recovered too - see class docblock.
        $this->assertSame('ACTIVE', ContractStatuses::code((int) $this->contractRow($contractUuid)->status_id));
        $this->assertNull($this->billingRow($contractUuid)->billing_suspended_at);
        $this->assertSame(1, $this->statusTransitionCount($contractUuid, 'SUSPENDED', 'ACTIVE'));

        $slot = $this->createAppointmentSlot();
        $response = $this->bookContractService($built['customer']['access_token'], $contractUuid, UuidBinary::toString($built['item']->id), $slot['uuid']);
        $response->assertStatus(201);
    }

    /**
     * Audit point C: the outbound provider-side cancellation request must
     * be sent exactly once per Contract, even if the Admin cancel endpoint
     * is retried/called again before the eventual customer.subscription.deleted
     * webhook has reconciled `service_contract_billings.status_id` to
     * CANCELLED - i.e. the guard must not rely on that local status alone.
     */
    public function test_admin_cancel_sends_provider_cancellation_exactly_once_across_retries_before_webhook(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        // BLUE V1 Phase A2.5 - contracts.cancel now also requires a fresh
        // WebAuthn step-up; grant it directly (reusable across all three
        // calls below - step-up is a window, not consumed per action).
        $this->markStepUpVerified($built['admin']['session_uuid']);

        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);
        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);
        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);

        // The webhook never arrived in this test - billing is still
        // unreconciled (ACTIVE) locally, proving the once-only guard is not
        // simply "skip if billing already reads CANCELLED".
        $this->assertSame('ACTIVE', $this->billingStatusCode($contractUuid));
        $this->assertSame([$subscriptionId], $this->fakeBillingGateway()->cancelSubscriptionCalls);
    }

    public function test_subscription_deleted_webhook_cancels_billing(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_deleted_'.UuidBinary::generate(),
            'event_type' => 'customer.subscription.deleted',
            'stripe_subscription_id' => $subscriptionId,
            'canceled_at' => now()->toDateTimeString(),
        ]))->assertStatus(200);

        $billing = $this->billingRow($contractUuid);
        $this->assertSame('CANCELLED', $this->billingStatusCode($contractUuid));
        $this->assertNotNull($billing->cancelled_at);
    }

    public function test_admin_cancel_requests_provider_subscription_cancellation_exactly_once(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        // BLUE V1 Phase A2.5 - see note in
        // test_admin_cancel_sends_provider_cancellation_exactly_once_across_retries_before_webhook.
        $this->markStepUpVerified($built['admin']['session_uuid']);

        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);

        $this->assertSame([$subscriptionId], $this->fakeBillingGateway()->cancelSubscriptionCalls);
    }

    public function test_no_payment_attempts_are_ever_created_for_contract_billing(): void
    {
        $built = $this->activeContractWithItem();

        $this->assertSame(0, DB::table('payment_attempts')->count());
    }

    public function test_standard_stripe_paymentintent_flow_is_unaffected_by_contract_billing_tables(): void
    {
        $payment = $this->successfulPayment();

        $this->assertSame(0, DB::table('service_contract_billings')->count());
        $this->assertSame(0, DB::table('service_contract_billing_webhook_events')->count());
        $this->assertNotNull($payment['payment']->successful_at);
    }

    // =====================================================================
    // BLOCKER 1 - provider cancellation must be durably retryable
    // =====================================================================

    /**
     * Requirement A: the operational Contract transition to CANCELLED must
     * never depend on the provider cancellation attempt succeeding.
     */
    public function test_admin_cancel_transitions_contract_to_cancelled_even_when_provider_call_fails(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);

        // BLUE V1 Phase A2.5 - see note in
        // test_admin_cancel_sends_provider_cancellation_exactly_once_across_retries_before_webhook.
        $this->markStepUpVerified($built['admin']['session_uuid']);

        $this->fakeBillingGateway()->queueCancellationFailure(new \RuntimeException('simulated provider outage'));

        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);

        $contract = $this->contractRow($contractUuid);
        $this->assertSame('CANCELLED', ContractStatuses::code((int) $contract->status_id));
    }

    /**
     * Requirement B: a failed provider delivery attempt must leave a
     * durable pending-cancellation marker behind, never silently drop the
     * request.
     */
    public function test_failed_provider_cancellation_leaves_durable_pending_marker(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        // BLUE V1 Phase A2.5 - see note in
        // test_admin_cancel_sends_provider_cancellation_exactly_once_across_retries_before_webhook.
        $this->markStepUpVerified($built['admin']['session_uuid']);

        $this->fakeBillingGateway()->queueCancellationFailure(new \RuntimeException('simulated provider outage'));
        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);

        $billing = $this->billingRow($contractUuid);
        $this->assertNotNull($billing->provider_cancellation_requested_at);
        $this->assertNotNull($billing->provider_cancellation_last_attempt_at);
        $this->assertSame(1, (int) $billing->provider_cancellation_attempt_count);
        $this->assertNull($billing->cancelled_at);
        $this->assertSame([$subscriptionId], $this->fakeBillingGateway()->cancelSubscriptionCalls);
    }

    /**
     * Requirement C: contracts:retry-pending-billing-cancellations later
     * re-invokes the provider cancellation for a still-pending request.
     */
    public function test_retry_command_reattempts_cancellation_after_a_prior_provider_failure(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        // BLUE V1 Phase A2.5 - see note in
        // test_admin_cancel_sends_provider_cancellation_exactly_once_across_retries_before_webhook.
        $this->markStepUpVerified($built['admin']['session_uuid']);

        $this->fakeBillingGateway()->queueCancellationFailure(new \RuntimeException('simulated provider outage'));
        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);
        $this->assertSame([$subscriptionId], $this->fakeBillingGateway()->cancelSubscriptionCalls);

        $retried = app(RetryPendingContractBillingCancellationsAction::class)->handle();

        $this->assertSame(1, $retried);
        $this->assertSame([$subscriptionId, $subscriptionId], $this->fakeBillingGateway()->cancelSubscriptionCalls);

        $billing = $this->billingRow($contractUuid);
        $this->assertSame(2, (int) $billing->provider_cancellation_attempt_count);
        $this->assertNull($billing->cancelled_at);
    }

    /**
     * Requirement D + G: once the webhook finally confirms the
     * cancellation, the row stops being retried - proving a provider outage
     * can never leave a subscription permanently un-cancelled AND that
     * reconciliation reliably ends the retry loop.
     */
    public function test_subscription_deleted_webhook_after_retry_ends_the_pending_retry_loop(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        // BLUE V1 Phase A2.5 - see note in
        // test_admin_cancel_sends_provider_cancellation_exactly_once_across_retries_before_webhook.
        $this->markStepUpVerified($built['admin']['session_uuid']);

        $this->fakeBillingGateway()->queueCancellationFailure(new \RuntimeException('simulated provider outage'));
        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);
        app(RetryPendingContractBillingCancellationsAction::class)->handle();

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_deleted_after_retry_'.UuidBinary::generate(),
            'event_type' => 'customer.subscription.deleted',
            'stripe_subscription_id' => $subscriptionId,
            'canceled_at' => now()->toDateTimeString(),
        ]))->assertStatus(200);

        $billing = $this->billingRow($contractUuid);
        $this->assertSame('CANCELLED', $this->billingStatusCode($contractUuid));
        $this->assertNotNull($billing->cancelled_at);

        $retriedAfterReconciliation = app(RetryPendingContractBillingCancellationsAction::class)->handle();

        $this->assertSame(0, $retriedAfterReconciliation);
        $this->assertSame([$subscriptionId, $subscriptionId], $this->fakeBillingGateway()->cancelSubscriptionCalls);
    }

    /**
     * Requirement E: the retry command is safe to run repeatedly with
     * nothing pending.
     */
    public function test_retry_command_is_a_safe_no_op_when_nothing_is_pending(): void
    {
        $this->activeContractWithItem();

        $retried = app(RetryPendingContractBillingCancellationsAction::class)->handle();
        $this->assertSame(0, $retried);

        $retriedAgain = app(RetryPendingContractBillingCancellationsAction::class)->handle();
        $this->assertSame(0, $retriedAgain);

        $this->assertSame([], $this->fakeBillingGateway()->cancelSubscriptionCalls);
    }

    /**
     * Requirement F: multiple Admin cancel calls must never create more
     * than one logical cancellation request (the durable marker is stamped
     * exactly once).
     */
    public function test_repeated_admin_cancel_calls_never_create_multiple_pending_cancellation_requests(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);

        // BLUE V1 Phase A2.5 - see note in
        // test_admin_cancel_sends_provider_cancellation_exactly_once_across_retries_before_webhook.
        $this->markStepUpVerified($built['admin']['session_uuid']);

        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);
        $requestedAtFirst = $this->billingRow($contractUuid)->provider_cancellation_requested_at;

        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);
        $this->adminCancelContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);

        $billing = $this->billingRow($contractUuid);
        $this->assertSame($requestedAtFirst, $billing->provider_cancellation_requested_at);
        $this->assertSame(1, (int) $billing->provider_cancellation_attempt_count);
    }

    // =====================================================================
    // BLOCKER 2 - billing-caused suspension must recover automatically
    // =====================================================================

    /**
     * Requirements A-D: a Contract suspended by
     * SuspendContractsPastDueBillingAction recovers to ACTIVE the moment
     * billing repairs itself, with exactly one recovery history row, its
     * `billing_suspended_at` marker cleared, and Contract Booking working
     * again immediately.
     */
    public function test_billing_caused_suspension_recovers_contract_to_active_on_invoice_paid(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_failed_'.UuidBinary::generate(),
            'event_type' => 'invoice.payment_failed',
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);

        config(['contract_billing.grace_days' => 0]);
        $suspended = app(SuspendContractsPastDueBillingAction::class)->handle();
        $this->assertSame(1, $suspended);
        $this->assertNotNull($this->billingRow($contractUuid)->billing_suspended_at);

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_recovered_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]))->assertStatus(200);

        $contract = $this->contractRow($contractUuid);
        $this->assertSame('ACTIVE', ContractStatuses::code((int) $contract->status_id));
        $this->assertSame(1, $this->statusTransitionCount($contractUuid, 'SUSPENDED', 'ACTIVE'));
        $this->assertNull($this->billingRow($contractUuid)->billing_suspended_at);

        $slot = $this->createAppointmentSlot();
        $response = $this->bookContractService($built['customer']['access_token'], $contractUuid, UuidBinary::toString($built['item']->id), $slot['uuid']);
        $response->assertStatus(201);
    }

    /**
     * Requirement E: a MANUALLY Admin-suspended Contract must never be
     * auto-recovered - billing may repair itself, but the Contract stays
     * SUSPENDED until an Admin acts.
     */
    public function test_manually_suspended_contract_is_never_auto_recovered_by_billing_repair(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_failed_'.UuidBinary::generate(),
            'event_type' => 'invoice.payment_failed',
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);
        $this->assertSame('PAST_DUE', $this->billingStatusCode($contractUuid));

        $this->adminSuspendContract($built['admin']['access_token'], $contractUuid)->assertStatus(200);
        $this->assertNull($this->billingRow($contractUuid)->billing_suspended_at);

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_paid_after_manual_suspend_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]))->assertStatus(200);

        $this->assertSame('ACTIVE', $this->billingStatusCode($contractUuid));
        $contract = $this->contractRow($contractUuid);
        $this->assertSame('SUSPENDED', ContractStatuses::code((int) $contract->status_id));
        $this->assertSame(0, $this->statusTransitionCount($contractUuid, 'SUSPENDED', 'ACTIVE'));
    }

    /**
     * Requirement F: a subsequent invoice.paid delivered after the Contract
     * has already recovered must never write a second recovery history row.
     */
    public function test_duplicate_invoice_paid_after_billing_recovery_does_not_duplicate_history(): void
    {
        $built = $this->activeContractWithItem();
        $contractUuid = UuidBinary::toString($built['contract']->id);
        $subscriptionId = $this->billingRow($contractUuid)->stripe_subscription_id;

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_failed_'.UuidBinary::generate(),
            'event_type' => 'invoice.payment_failed',
            'stripe_subscription_id' => $subscriptionId,
        ]))->assertStatus(200);

        config(['contract_billing.grace_days' => 0]);
        app(SuspendContractsPastDueBillingAction::class)->handle();

        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_recovered_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]))->assertStatus(200);
        $this->assertSame(1, $this->statusTransitionCount($contractUuid, 'SUSPENDED', 'ACTIVE'));

        // A later, distinctly-delivered invoice.paid (e.g. the next
        // renewal) for the now-ACTIVE Contract must never write a second
        // recovery-shaped history row.
        $this->postContractBillingWebhook($this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_renewal_after_recovery_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]))->assertStatus(200);

        $this->assertSame(1, $this->statusTransitionCount($contractUuid, 'SUSPENDED', 'ACTIVE'));

        // The exact same webhook delivery replayed (identical event id) is
        // already independently idempotent via the webhook ledger dedup -
        // confirmed here too for completeness.
        $replayedPayload = $this->fakeContractBillingWebhookPayload([
            'event_id' => 'evt_exact_replay_'.UuidBinary::generate(),
            'event_type' => 'invoice.paid',
            'stripe_subscription_id' => $subscriptionId,
            'invoice_paid' => true,
        ]);
        $this->postContractBillingWebhook($replayedPayload)->assertStatus(200);
        $this->postContractBillingWebhook($replayedPayload)->assertStatus(200);

        $this->assertSame(1, $this->statusTransitionCount($contractUuid, 'SUSPENDED', 'ACTIVE'));
    }
}
