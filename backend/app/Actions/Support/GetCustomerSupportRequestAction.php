<?php

namespace App\Actions\Support;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Support\CustomerSupportRequestPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GetCustomerSupportRequestAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $customerUserUuid, string $supportRequestUuid): array
    {
        try {
            $supportRequestIdBinary = UuidBinary::toBinary($supportRequestUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Support request not found.');
        }

        $row = DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
            ->where('support_requests.id', $supportRequestIdBinary)
            ->where('support_requests.customer_user_id', UuidBinary::toBinary($customerUserUuid))
            ->first(['support_requests.*', 'support_request_statuses.code as status']);

        if ($row === null) {
            return $this->notFound('Support request not found.');
        }

        return $this->ok(200, 'Support request retrieved successfully.', [
            'support_request' => CustomerSupportRequestPresenter::detail($row),
        ]);
    }
}
