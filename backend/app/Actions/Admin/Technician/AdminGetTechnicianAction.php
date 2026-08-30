<?php

namespace App\Actions\Admin\Technician;

use App\Support\Admin\AdminTechnicianPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use InvalidArgumentException;

/**
 * Read one Technician's full Admin profile - Overview + Performance +
 * Current Work (BLUE V1 Technician Admin Management section 10). Booking/
 * Job history and Ratings history are separate paginated endpoints
 * (AdminListTechnicianJobsAction / AdminListTechnicianRatingsAction) - see
 * section 25's "do not return large histories in one response".
 */
final class AdminGetTechnicianAction
{
    use BuildsCartResult;

    public function handle(string $technicianUuid): array
    {
        try {
            $technicianIdBinary = UuidBinary::toBinary($technicianUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Technician not found.');
        }

        $technician = AdminTechnicianPresenter::loadForDetail($technicianIdBinary);

        if ($technician === null) {
            return $this->notFound('Technician not found.');
        }

        return $this->ok(200, 'Technician retrieved successfully.', ['technician' => AdminTechnicianPresenter::detail($technician)]);
    }
}
