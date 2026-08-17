<?php

namespace App\Actions\Contract;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\ContractPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, ownership-scoped Service Contract listing (GET /v1/contracts) -
 * every Contract belonging to the authenticated customer, newest first.
 * Mirrors App\Actions\Booking\ListBookingsAction's shape/ownership
 * conventions.
 */
class ListContractsAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid): array
    {
        $userIdBinary = UuidBinary::toBinary($userUuid);
        $now = now();

        $contracts = DB::table('service_contracts')
            ->where('customer_user_id', $userIdBinary)
            ->orderByDesc('created_at')
            ->get();

        return $this->ok(200, 'Service contracts retrieved successfully.', [
            'contracts' => $contracts->map(fn (object $contract) => ContractPresenter::summary($contract, $now))->all(),
        ]);
    }
}
