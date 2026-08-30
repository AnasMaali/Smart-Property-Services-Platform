<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin-facing Payment Attempt JSON shape (BLUE V1 Phase B5) -
 * deliberately separate from App\Support\Payment\PaymentPresenter, for the
 * same reasons App\Support\Admin\AdminBookingPresenter is separate from
 * App\Support\Booking\BookingPresenter: App\Actions\Payment\GetPaymentAction
 * is ownership-scoped to one customer and its presenter is deliberately
 * minimal (it never returns confirmed_amount, provider transaction
 * reference, failure detail, reconciliation state, or timestamps beyond
 * `expires_at`) - none of that is a customer concern, but all of it is
 * exactly what an Admin operator needs to understand what happened
 * financially.
 *
 * Never exposes `checkout_snapshot`/`checkout_snapshot_hash` (the frozen
 * cart-price snapshot and its integrity hash), `idempotency_key`, or any
 * raw binary(16) id - only through UuidBinary. `provider_session_reference`/
 * `provider_transaction_reference` are Stripe object identifiers, not
 * secrets (the same safe-identifier posture already established for
 * `stripe_subscription_id` etc. in App\Support\Contract\Billing\
 * ContractBillingPresenter::adminView()) - never a client_secret or any
 * provider credential.
 */
final class AdminPaymentPresenter
{
    /**
     * Batch-loaded Admin Payment list row shape - never issues a query per
     * payment. Every row in $rows must already carry `carts.customer_user_id`
     * alongside the raw `payment_attempts` columns (see
     * App\Actions\Admin\Payment\AdminListPaymentsAction).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $paymentIds = $rows->pluck('id')->all();
        $customerIds = $rows->pluck('customer_user_id')->unique()->values()->all();
        $currencyIds = $rows->pluck('currency_id')->unique()->values()->all();

        $customers = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $customerIds)
            ->get(['users.id', 'users.phone_number', 'user_profiles.full_name'])
            ->keyBy(fn ($row) => $row->id);

        $currencies = DB::table('currencies')
            ->whereIn('id', $currencyIds)
            ->get(['id', 'code', 'symbol', 'minor_unit'])
            ->keyBy(fn ($row) => $row->id);

        $statuses = DB::table('payment_statuses')->get(['id', 'code'])->keyBy('id');

        $bookingUuidsByPayment = DB::table('bookings')
            ->whereIn('payment_attempt_id', $paymentIds)
            ->pluck('id', 'payment_attempt_id');

        return $rows->map(function (object $row) use ($customers, $currencies, $statuses, $bookingUuidsByPayment): array {
            $customer = $customers->get($row->customer_user_id);
            $currency = $currencies->get($row->currency_id);
            $bookingId = $bookingUuidsByPayment->get($row->id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'checkout_reference' => $row->checkout_reference,
                'status' => $statuses->get($row->status_id)?->code,
                'customer' => $customer === null ? null : [
                    'uuid' => UuidBinary::toString($customer->id),
                    'full_name' => $customer->full_name,
                    'phone_number' => $customer->phone_number,
                ],
                'requested_amount' => $row->requested_amount,
                'confirmed_amount' => $row->confirmed_amount,
                'currency' => $currency === null ? null : [
                    'code' => $currency->code,
                    'symbol' => $currency->symbol,
                    'decimal_places' => (int) $currency->minor_unit,
                ],
                'provider' => $row->provider_code,
                'booking_uuid' => $bookingId === null ? null : UuidBinary::toString($bookingId),
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'status_changed_at' => Carbon::parse($row->status_changed_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Full Admin Payment detail shape - $row must carry
     * `carts.customer_user_id` alongside the raw `payment_attempts` columns
     * (see App\Actions\Admin\Payment\AdminGetPaymentAction).
     *
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $status = DB::table('payment_statuses')->where('id', $row->status_id)->value('code');
        $currency = DB::table('currencies')->where('id', $row->currency_id)->first(['code', 'symbol', 'minor_unit']);

        $customer = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $row->customer_user_id)
            ->first(['users.id', 'users.phone_number', 'users.email', 'user_profiles.full_name']);

        $booking = DB::table('bookings')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->where('bookings.payment_attempt_id', $row->id)
            ->first(['bookings.id', 'bookings.booking_number', 'booking_statuses.code as status_code']);

        $webhookEvents = DB::table('payment_webhook_events')
            ->join('payment_webhook_event_statuses', 'payment_webhook_event_statuses.id', '=', 'payment_webhook_events.status_id')
            ->where('payment_webhook_events.payment_attempt_id', $row->id)
            ->orderByDesc('payment_webhook_events.received_at')
            ->limit(20)
            ->get([
                'payment_webhook_events.provider_event_id',
                'payment_webhook_events.event_type',
                'payment_webhook_event_statuses.code as status_code',
                'payment_webhook_events.received_at',
                'payment_webhook_events.processed_at',
                'payment_webhook_events.last_error_code',
                'payment_webhook_events.last_error_message',
            ]);

        return [
            'uuid' => UuidBinary::toString($row->id),
            'checkout_reference' => $row->checkout_reference,
            'status' => $status,
            'customer' => $customer === null ? null : [
                'uuid' => UuidBinary::toString($customer->id),
                'full_name' => $customer->full_name,
                'phone_number' => $customer->phone_number,
                'email' => $customer->email,
            ],
            'booking' => $booking === null ? null : [
                'uuid' => UuidBinary::toString($booking->id),
                'booking_number' => $booking->booking_number,
                'status' => $booking->status_code,
            ],
            'requested_amount' => $row->requested_amount,
            'confirmed_amount' => $row->confirmed_amount,
            'currency' => $currency === null ? null : [
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'decimal_places' => (int) $currency->minor_unit,
            ],
            'provider' => $row->provider_code,
            'provider_session_reference' => $row->provider_session_reference,
            'provider_transaction_reference' => $row->provider_transaction_reference,
            'provider_status_code' => $row->provider_status_code,
            'payment_method_type' => $row->payment_method_type,
            'failure_code' => $row->failure_code,
            'failure_message' => $row->failure_message,
            'requires_reconciliation' => (bool) $row->requires_reconciliation,
            'reconciliation_reason_code' => $row->reconciliation_reason_code,
            'reconciled_at' => $row->reconciled_at === null ? null : Carbon::parse($row->reconciled_at)->toIso8601String(),
            'expires_at' => $row->expires_at === null ? null : Carbon::parse($row->expires_at)->toIso8601String(),
            'successful_at' => $row->successful_at === null ? null : Carbon::parse($row->successful_at)->toIso8601String(),
            'finalized_at' => $row->finalized_at === null ? null : Carbon::parse($row->finalized_at)->toIso8601String(),
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'status_changed_at' => Carbon::parse($row->status_changed_at)->toIso8601String(),
            'recent_webhook_events' => $webhookEvents->map(fn (object $event): array => [
                'provider_event_id' => $event->provider_event_id,
                'event_type' => $event->event_type,
                'status' => $event->status_code,
                'received_at' => Carbon::parse($event->received_at)->toIso8601String(),
                'processed_at' => $event->processed_at === null ? null : Carbon::parse($event->processed_at)->toIso8601String(),
                'last_error_code' => $event->last_error_code,
                'last_error_message' => $event->last_error_message,
            ])->all(),
        ];
    }
}
