<?php

namespace App\Actions\Support;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Support\CustomerSupportRequestPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SendCustomerSupportMessageAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $customerUserUuid, string $supportRequestUuid, string $messageBody): array
    {
        try {
            $supportRequestIdBinary = UuidBinary::toBinary($supportRequestUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Support request not found.');
        }

        return DB::transaction(function () use ($customerUserUuid, $supportRequestIdBinary, $messageBody): array {
            $supportRequest = DB::table('support_requests')
                ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
                ->where('support_requests.id', $supportRequestIdBinary)
                ->where('support_requests.customer_user_id', UuidBinary::toBinary($customerUserUuid))
                ->first(['support_requests.*', 'support_request_statuses.code as status']);

            if ($supportRequest === null) {
                return $this->notFound('Support request not found.');
            }

            if (in_array($supportRequest->status, ['CLOSED', 'RESOLVED'], true)) {
                return $this->conflict('This support request is closed and cannot receive new messages.');
            }

            $now = now();
            $messageUuid = UuidBinary::generate();

            DB::table('support_messages')->insert([
                'id' => UuidBinary::toBinary($messageUuid),
                'support_request_id' => $supportRequestIdBinary,
                'sender_user_id' => UuidBinary::toBinary($customerUserUuid),
                'message_body' => trim($messageBody),
                'created_at' => $now,
            ]);

            DB::table('support_requests')
                ->where('id', $supportRequestIdBinary)
                ->update(['updated_at' => $now]);

            $senderIds = [UuidBinary::toBinary($customerUserUuid)];

            return $this->ok(201, 'Message sent successfully.', [
                'message' => [
                    'uuid' => $messageUuid,
                    'from_support' => false,
                    'message_body' => trim($messageBody),
                    'created_at' => $now->toIso8601String(),
                ],
            ]);
        });
    }
}
