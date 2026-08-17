<?php

namespace App\Actions\Property;

use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Property\PropertyPresenter;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, ownership-scoped Property listing (GET /v1/properties) - every
 * Property belonging to the authenticated customer, newest first. Active
 * properties are returned by default; archived ones stay historically
 * queryable (never hard-deleted - see App\Actions\Property\ArchivePropertyAction)
 * and are included only when explicitly asked for, so a customer's everyday
 * "pick a property" list never has to filter out old archived rows itself.
 */
class ListPropertiesAction
{
    use BuildsCartResult;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $userUuid, string $filter = 'active'): array
    {
        $userIdBinary = UuidBinary::toBinary($userUuid);

        $query = DB::table('customer_properties')->where('customer_user_id', $userIdBinary);

        if ($filter === 'active') {
            $query->where('is_active', 1);
        } elseif ($filter === 'archived') {
            $query->where('is_active', 0);
        }

        $properties = $query->orderByDesc('created_at')->get();

        return $this->ok(200, 'Properties retrieved successfully.', [
            'properties' => $properties->map(PropertyPresenter::present(...))->all(),
        ]);
    }
}
