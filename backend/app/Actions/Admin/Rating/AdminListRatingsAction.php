<?php

namespace App\Actions\Admin\Rating;

use App\Support\Admin\AdminRatingPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Global (cross-customer) Ratings listing for Admin operators (BLUE V1
 * Phase B11) - reads the exact canonical `ratings` rows, never a parallel
 * feedback store. Deterministic ordering (`created_at DESC, booking_id
 * DESC`) and a bounded page size make this safe against an unbounded table.
 */
final class AdminListRatingsAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    /**
     * @param  array{rating_value?: int, max_rating?: int, booking_uuid?: string, customer_uuid?: string}  $filters
     */
    public function handle(array $filters, int $page, int $perPage): array
    {
        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        foreach (['booking_uuid', 'customer_uuid'] as $uuidFilter) {
            if (isset($filters[$uuidFilter])) {
                try {
                    $filters[$uuidFilter] = UuidBinary::toBinary($filters[$uuidFilter]);
                } catch (InvalidArgumentException) {
                    return $this->ok(200, 'Ratings retrieved successfully.', [
                        'ratings' => [],
                        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
                    ]);
                }
            }
        }

        $query = DB::table('ratings')
            ->join('bookings', 'bookings.id', '=', 'ratings.booking_id')
            ->join('carts', 'carts.id', '=', 'bookings.cart_id');

        if (isset($filters['rating_value'])) {
            $query->where('ratings.rating_value', $filters['rating_value']);
        }

        if (isset($filters['max_rating'])) {
            $query->where('ratings.rating_value', '<=', $filters['max_rating']);
        }

        if (isset($filters['booking_uuid'])) {
            $query->where('ratings.booking_id', $filters['booking_uuid']);
        }

        if (isset($filters['customer_uuid'])) {
            $query->where('carts.customer_user_id', $filters['customer_uuid']);
        }

        $total = (clone $query)->count('ratings.booking_id');
        $lastPage = max((int) ceil($total / $perPage), 1);

        $rows = $query
            ->orderByDesc('ratings.created_at')
            ->orderByDesc('ratings.booking_id')
            ->forPage($page, $perPage)
            ->get([
                'ratings.booking_id',
                'ratings.rating_value',
                'ratings.comment',
                'ratings.created_at',
                'bookings.booking_number',
                'carts.customer_user_id',
            ]);

        return $this->ok(200, 'Ratings retrieved successfully.', [
            'ratings' => AdminRatingPresenter::presentList($rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
