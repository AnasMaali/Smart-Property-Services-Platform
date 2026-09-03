<?php

namespace App\Actions\Support;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Support\CustomerSupportRequestPresenter;
use App\Support\Support\SupportRequestNumberGenerator;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateCustomerSupportRequestAction
{
    use BuildsCartResult;

    /**
     * @param  array{subject: string, message: string, booking_uuid?: ?string}  $data
     * @return array<string, mixed>
     */
    public function handle(string $customerUserUuid, array $data): array
    {
        $bookingIdBinary = null;

        if (! empty($data['booking_uuid'])) {
            try {
                $bookingIdBinary = UuidBinary::toBinary($data['booking_uuid']);
            } catch (InvalidArgumentException) {
                return $this->unprocessable('The selected booking is invalid.', ['booking_uuid' => 'Invalid booking UUID.']);
            }

            $owned = DB::table('bookings')
                ->join('carts', 'carts.id', '=', 'bookings.cart_id')
                ->where('bookings.id', $bookingIdBinary)
                ->where('carts.customer_user_id', UuidBinary::toBinary($customerUserUuid))
                ->exists();

            if (! $owned) {
                return $this->notFound('Booking not found.');
            }
        }

        return DB::transaction(function () use ($customerUserUuid, $data, $bookingIdBinary): array {
            $now = now();
            $requestUuid = UuidBinary::generate();
            $requestIdBinary = UuidBinary::toBinary($requestUuid);
            $openStatusId = (int) DB::table('support_request_statuses')->where('code', 'OPEN')->value('id');

            DB::table('support_requests')->insert([
                'id' => $requestIdBinary,
                'request_number' => SupportRequestNumberGenerator::generate(),
                'customer_user_id' => UuidBinary::toBinary($customerUserUuid),
                'booking_id' => $bookingIdBinary,
                'status_id' => $openStatusId,
                'assigned_admin_user_id' => null,
                'subject' => trim($data['subject']),
                'status_changed_at' => $now,
                'resolved_at' => null,
                'closed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('support_messages')->insert([
                'id' => UuidBinary::toBinary(UuidBinary::generate()),
                'support_request_id' => $requestIdBinary,
                'sender_user_id' => UuidBinary::toBinary($customerUserUuid),
                'message_body' => trim($data['message']),
                'created_at' => $now,
            ]);

            $row = DB::table('support_requests')
                ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
                ->where('support_requests.id', $requestIdBinary)
                ->first(['support_requests.*', 'support_request_statuses.code as status']);

            return $this->ok(201, 'Support request created successfully.', [
                'support_request' => CustomerSupportRequestPresenter::detail($row),
            ]);
        });
    }
}
