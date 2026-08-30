<?php

namespace App\Actions\Admin\Booking;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminBookingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BLUE V1 Phase B24 - the ONE place an Admin records that a Pay-on-Site
 * Booking's cash was actually collected. Full-amount-only (V1, per the
 * phase spec - no partial-payment behavior). Transactional, idempotent (a
 * second call against an already-collected settlement is a safe no-op, not
 * an error - the same "already in target state" convention
 * App\Actions\Booking\TransitionBookingStatusAction already uses), and
 * NEVER touches the Booking's own price (`booking_items.line_total_amount`
 * / `pricing_breakdown`) - only `booking_on_site_settlements.
 * amount_collected` is ever written, always equal to `amount_due`, never a
 * client-supplied amount.
 *
 * Deliberately never mutates `payment_attempts` - a Pay-on-Site Booking has
 * none (see App\Actions\Booking\CreatePayOnSiteBookingAction's docblock),
 * and that table is online-payment-provider-specific by design; cash
 * collection is truthfully a different domain, recorded here instead.
 */
final class AdminCollectOnSitePaymentAction
{
    use BuildsCartResult;

    public function handle(Request $request, User $actor, string $bookingUuid): array
    {
        try {
            $bookingIdBinary = UuidBinary::toBinary($bookingUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Booking not found.');
        }

        return DB::transaction(function () use ($request, $bookingUuid, $bookingIdBinary, $actor): array {
            $booking = DB::table('bookings')->where('id', $bookingIdBinary)->lockForUpdate()->first(['id', 'cart_id']);

            if ($booking === null) {
                return $this->notFound('Booking not found.');
            }

            $settlement = DB::table('booking_on_site_settlements')->where('booking_id', $bookingIdBinary)->lockForUpdate()->first();

            if ($settlement === null) {
                return $this->unprocessable('This Booking has no on-site settlement to collect - it was not created as a Pay-on-Site Booking.');
            }

            if ($settlement->collected_at !== null) {
                return $this->ok(200, 'Payment was already marked as collected.', ['booking' => $this->present($bookingIdBinary)]);
            }

            $now = now();

            DB::table('booking_on_site_settlements')->where('id', $settlement->id)->update([
                'amount_collected' => $settlement->amount_due,
                'collected_at' => $now,
                'collected_by_admin_user_id' => UuidBinary::toBinary($actor->id),
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'BOOKING_ON_SITE_PAYMENT_COLLECTED',
                'BOOKING',
                $bookingUuid,
                ['amount_collected' => (string) $settlement->amount_due],
            );

            return $this->ok(200, 'Payment marked as collected.', ['booking' => $this->present($bookingIdBinary)]);
        });
    }

    private function present(string $bookingIdBinary): array
    {
        $withCurrency = DB::table('bookings')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id')
            ->where('bookings.id', $bookingIdBinary)
            ->first(['bookings.*', 'carts.customer_user_id', 'carts.currency_id as cart_currency_id']);

        return AdminBookingPresenter::detail($withCurrency);
    }
}
