<?php

namespace App\Actions\Contract;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Contract\ContractPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only, ownership-scoped Service Contract lookup
 * (GET /v1/contracts/{contract}). A foreign or unknown Contract UUID is
 * reported identically as 404, never 403, matching
 * App\Actions\Booking\GetBookingAction's convention.
 */
class GetContractAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $contractUuid): array
    {
        try {
            $contractIdBinary = UuidBinary::toBinary($contractUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service contract not found.');
        }

        $userIdBinary = UuidBinary::toBinary($userUuid);

        $contract = DB::table('service_contracts')
            ->where('id', $contractIdBinary)
            ->where('customer_user_id', $userIdBinary)
            ->first();

        if ($contract === null) {
            return $this->notFound('Service contract not found.');
        }

        return $this->ok(200, 'Service contract retrieved successfully.', ['contract' => ContractPresenter::detail($contract, now())]);
    }
}
