<?php

namespace App\Actions\Property;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * "Deletes" a Property by archiving it (DELETE /v1/properties/{property}) -
 * never a destructive row delete, since a Property referenced by a Service
 * Contract must remain historically queryable (BLUE V1 Phase 10A/10B).
 * Idempotent: archiving an already-archived Property is a safe no-op that
 * still returns 200, matching App\Actions\Booking\CancelBookingAction's
 * "retry reads the already-settled state back" convention.
 */
class ArchivePropertyAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $propertyUuid): array
    {
        try {
            $propertyIdBinary = UuidBinary::toBinary($propertyUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Property not found.');
        }

        return DB::transaction(function () use ($propertyIdBinary, $userUuid): array {
            $userIdBinary = UuidBinary::toBinary($userUuid);

            $property = DB::table('customer_properties')
                ->where('id', $propertyIdBinary)
                ->where('customer_user_id', $userIdBinary)
                ->lockForUpdate()
                ->first();

            if ($property === null) {
                return $this->notFound('Property not found.');
            }

            if ((int) $property->is_active === 1) {
                DB::table('customer_properties')->where('id', $propertyIdBinary)->update([
                    'is_active' => 0,
                    'updated_at' => now(),
                ]);
            }

            return $this->ok(200, 'Property archived successfully.', [
                'property' => ['uuid' => UuidBinary::toString($propertyIdBinary), 'is_active' => false],
            ]);
        });
    }
}
