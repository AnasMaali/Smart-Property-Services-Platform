<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Admin-facing Support Request JSON shape (BLUE V1 Phase B7) - the
 * first and only presenter for this domain, since no customer-facing
 * Support implementation exists yet (`support_requests`/`support_messages`/
 * `support_request_statuses` are provisioned in the schema but have no
 * application code of their own to reuse or avoid duplicating).
 *
 * Never exposes `password_hash`, any OTP/session/refresh-token/WebAuthn
 * material, or any raw binary(16) id - only through UuidBinary.
 */
final class AdminSupportRequestPresenter
{
    private const ADMIN_ROLE_CODES = ['ADMIN', 'SUPER_ADMIN'];

    /**
     * Batch-loaded Admin Support Request list row shape - never issues a
     * query per row. Every row in $rows must already carry
     * `support_request_statuses.code as status` alongside the raw
     * `support_requests` columns (see App\Actions\Admin\Support\
     * AdminListSupportRequestsAction).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $customerIds = $rows->pluck('customer_user_id')->unique()->values()->all();
        $assignedAdminIds = $rows->pluck('assigned_admin_user_id')->filter()->unique()->values()->all();
        $bookingIds = $rows->pluck('booking_id')->filter()->unique()->values()->all();

        $customers = self::userSummaries($customerIds);
        $assignedAdmins = $assignedAdminIds === [] ? collect() : self::userSummaries($assignedAdminIds);

        $bookings = $bookingIds === [] ? collect() : DB::table('bookings')
            ->whereIn('id', $bookingIds)
            ->get(['id', 'booking_number'])
            ->keyBy('id');

        return $rows->map(function (object $row) use ($customers, $assignedAdmins, $bookings): array {
            $customer = $customers->get($row->customer_user_id);
            $assignedAdmin = $row->assigned_admin_user_id === null ? null : $assignedAdmins->get($row->assigned_admin_user_id);
            $booking = $row->booking_id === null ? null : $bookings->get($row->booking_id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'request_number' => $row->request_number,
                'subject' => $row->subject,
                'status' => $row->status,
                'customer' => $customer === null ? null : [
                    'uuid' => UuidBinary::toString($row->customer_user_id),
                    'full_name' => $customer->full_name,
                    'phone_number' => $customer->phone_number,
                ],
                'booking' => $booking === null ? null : [
                    'uuid' => UuidBinary::toString($booking->id),
                    'booking_number' => $booking->booking_number,
                ],
                'assigned_admin' => $assignedAdmin === null ? null : [
                    'uuid' => UuidBinary::toString($row->assigned_admin_user_id),
                    'full_name' => $assignedAdmin->full_name,
                ],
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'status_changed_at' => Carbon::parse($row->status_changed_at)->toIso8601String(),
            ];
        })->all();
    }

    /**
     * Full Admin Support Request detail shape - $row must carry
     * `support_request_statuses.code as status` alongside the raw
     * `support_requests` columns (see App\Actions\Admin\Support\
     * AdminGetSupportRequestAction). Includes every message on the
     * request, oldest first - a Support conversation for a small
     * property-services operator is realistically a handful of messages
     * (never a high-volume chat/helpdesk thread), so this mirrors every
     * other bounded child-collection this codebase already returns
     * unpaginated on a single detail record (Contract status_history,
     * covered_services, linked bookings) rather than adding pagination
     * for a collection with no realistic need for it.
     *
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $customer = DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->where('users.id', $row->customer_user_id)
            ->first(['users.id', 'users.phone_number', 'users.email', 'user_profiles.full_name']);

        $booking = $row->booking_id === null ? null : DB::table('bookings')
            ->join('booking_statuses', 'booking_statuses.id', '=', 'bookings.status_id')
            ->where('bookings.id', $row->booking_id)
            ->first(['bookings.id', 'bookings.booking_number', 'booking_statuses.code as status_code']);

        $assignedAdmin = $row->assigned_admin_user_id === null ? null : self::userSummaries([$row->assigned_admin_user_id])->get($row->assigned_admin_user_id);

        $messages = DB::table('support_messages')
            ->where('support_request_id', $row->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $senderIds = $messages->pluck('sender_user_id')->unique()->values()->all();
        $senders = $senderIds === [] ? collect() : self::userSummaries($senderIds);
        $adminSenderIds = $senderIds === [] ? [] : DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('user_roles.user_id', $senderIds)
            ->whereIn('roles.code', self::ADMIN_ROLE_CODES)
            ->where('roles.is_active', 1)
            ->pluck('user_roles.user_id')
            ->unique()
            ->all();
        $adminSenderIds = array_flip($adminSenderIds);

        return [
            'uuid' => UuidBinary::toString($row->id),
            'request_number' => $row->request_number,
            'subject' => $row->subject,
            'status' => $row->status,
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
            'assigned_admin' => $assignedAdmin === null ? null : [
                'uuid' => UuidBinary::toString($row->assigned_admin_user_id),
                'full_name' => $assignedAdmin->full_name,
            ],
            'status_changed_at' => Carbon::parse($row->status_changed_at)->toIso8601String(),
            'resolved_at' => $row->resolved_at === null ? null : Carbon::parse($row->resolved_at)->toIso8601String(),
            'closed_at' => $row->closed_at === null ? null : Carbon::parse($row->closed_at)->toIso8601String(),
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
            'messages' => $messages->map(function (object $message) use ($row, $senders, $adminSenderIds): array {
                return [
                    'uuid' => UuidBinary::toString($message->id),
                    'sender' => self::senderPayload($message->sender_user_id, $row->customer_user_id, $senders, $adminSenderIds),
                    'message_body' => $message->message_body,
                    'created_at' => Carbon::parse($message->created_at)->toIso8601String(),
                ];
            })->all(),
        ];
    }

    /**
     * Classifies a message's sender relative to the Support Request's own
     * Customer, never guessed: CUSTOMER only if the sender is literally
     * this request's `customer_user_id`; ADMIN only if the sender
     * currently holds an active ADMIN/SUPER_ADMIN role; otherwise UNKNOWN
     * (never falsely labelled either way - e.g. a historical row from a
     * user who has since lost their Admin role, or any sender that is
     * neither).
     *
     * @return array{uuid: string, full_name: ?string, type: string}
     */
    private static function senderPayload(string $senderIdBinary, string $customerIdBinary, Collection $senders, array $adminSenderIds): array
    {
        $sender = $senders->get($senderIdBinary);

        $type = match (true) {
            $senderIdBinary === $customerIdBinary => 'CUSTOMER',
            isset($adminSenderIds[$senderIdBinary]) => 'ADMIN',
            default => 'UNKNOWN',
        };

        return [
            'uuid' => UuidBinary::toString($senderIdBinary),
            'full_name' => $sender?->full_name,
            'type' => $type,
        ];
    }

    /**
     * @param  array<int, string>  $userIdsBinary
     * @return Collection<string, object>
     */
    private static function userSummaries(array $userIdsBinary): Collection
    {
        return DB::table('users')
            ->join('user_profiles', 'user_profiles.user_id', '=', 'users.id')
            ->whereIn('users.id', $userIdsBinary)
            ->get(['users.id', 'users.phone_number', 'user_profiles.full_name'])
            ->keyBy('id');
    }
}
