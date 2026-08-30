<?php

namespace App\Actions\Admin\Technician;

use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Paginated Ratings history for one Technician (BLUE V1 Technician Admin
 * Management section 13) - see App\Support\Admin\AdminTechnicianPresenter's
 * docblock for the exclusivity rule that decides which ratings actually
 * count toward this Technician's average_rating/rating_count. Result set
 * is bounded by how many distinct Bookings this Technician was ever
 * assigned to (at most one rating per Booking), which never approaches the
 * scale a generic assignment-history endpoint would - in-memory pagination
 * over the already-batched attribution query is therefore safe here.
 */
final class AdminListTechnicianRatingsAction
{
    use BuildsCartResult;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function handle(string $technicianUuid, int $page, int $perPage): array
    {
        try {
            $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Technician not found.');
        }

        if (! DB::table('technicians')->where('id', $technicianIdBinary)->exists()) {
            return $this->notFound('Technician not found.');
        }

        $page = max($page, 1);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $all = AdminTechnicianPresenter::ratingsForTechnician($technicianIdBinary);
        $total = $all->count();
        $lastPage = max((int) ceil($total / $perPage), 1);
        $slice = $all->slice(($page - 1) * $perPage, $perPage);

        return $this->ok(200, 'Technician ratings retrieved successfully.', [
            'ratings' => AdminTechnicianPresenter::presentRatings($slice),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ]);
    }
}
