<?php

namespace App\Support\Support;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customer-facing Support Request JSON shape for the mobile app.
 */
final class CustomerSupportRequestPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $requestIds = $rows->pluck('id')->all();
        $messageCounts = DB::table('support_messages')
            ->whereIn('support_request_id', $requestIds)
            ->selectRaw('support_request_id, COUNT(*) as message_count, MAX(created_at) as last_message_at')
            ->groupBy('support_request_id')
            ->get()
            ->keyBy('support_request_id');

        return $rows->map(function (object $row) use ($messageCounts): array {
            $counts = $messageCounts->get($row->id);

            return [
                'uuid' => UuidBinary::toString($row->id),
                'request_number' => $row->request_number,
                'subject' => $row->subject,
                'status' => $row->status,
                'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
                'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
                'message_count' => $counts === null ? 0 : (int) $counts->message_count,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $messages = DB::table('support_messages')
            ->where('support_request_id', $row->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return [
            'uuid' => UuidBinary::toString($row->id),
            'request_number' => $row->request_number,
            'subject' => $row->subject,
            'status' => $row->status,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
            'message_count' => $messages->count(),
            'messages' => $messages->map(function (object $message) use ($row): array {
                return [
                    'uuid' => UuidBinary::toString($message->id),
                    'from_support' => $message->sender_user_id !== $row->customer_user_id,
                    'message_body' => $message->message_body,
                    'created_at' => Carbon::parse($message->created_at)->toIso8601String(),
                ];
            })->all(),
        ];
    }
}
