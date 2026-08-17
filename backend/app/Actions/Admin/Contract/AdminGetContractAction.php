<?php

namespace App\Actions\Admin\Contract;

use App\Support\Admin\AdminContractPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only Admin Service Contract detail lookup (BLUE V1 Phase 10E) -
 * unlike App\Actions\Contract\GetContractAction, never ownership-scoped to
 * one customer.
 */
final class AdminGetContractAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $contractUuid): array
    {
        try {
            $contractIdBinary = UuidBinary::toBinary($contractUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service contract not found.');
        }

        $contract = DB::table('service_contracts')->where('id', $contractIdBinary)->first();

        if ($contract === null) {
            return $this->notFound('Service contract not found.');
        }

        return $this->ok(200, 'Service contract retrieved successfully.', ['contract' => AdminContractPresenter::detail($contract)]);
    }
}
