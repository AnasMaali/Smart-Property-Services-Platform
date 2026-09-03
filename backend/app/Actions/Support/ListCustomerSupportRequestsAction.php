<?php

namespace App\Actions\Support;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Support\CustomerSupportRequestPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

final class ListCustomerSupportRequestsAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $customerUserUuid): array
    {
        $rows = DB::table('support_requests')
            ->join('support_request_statuses', 'support_request_statuses.id', '=', 'support_requests.status_id')
            ->where('support_requests.customer_user_id', UuidBinary::toBinary($customerUserUuid))
            ->orderByDesc('support_requests.created_at')
            ->get(['support_requests.*', 'support_request_statuses.code as status']);

        return $this->ok(200, 'Support requests retrieved successfully.', [
            'support_requests' => CustomerSupportRequestPresenter::presentList($rows),
        ]);
    }
}
