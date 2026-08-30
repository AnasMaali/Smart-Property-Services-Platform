<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Edits only the safe display metadata already represented in `services`
 * (name, short_description, description, display_order) - a full replace,
 * not a partial patch. `code`, `slug`, and `category_id` are deliberately
 * never editable here: `slug` is the customer-facing GET /v1/services/
 * {slug} lookup key (renaming it would break any existing deep link), and
 * re-categorizing a Service is a structural change with no established
 * safety story, not a "safe metadata" edit - see
 * App\Actions\Admin\Service\AdminGetServiceAction's docblock.
 */
final class AdminUpdateServiceMetadataAction
{
    use BuildsCartResult;

    /**
     * @param  array{name: string, short_description: ?string, description: ?string, display_order: int}  $metadata
     */
    public function handle(Request $request, string $serviceUuid, User $actor, array $metadata): array
    {
        try {
            $serviceIdBinary = UuidBinary::toBinary($serviceUuid);
        } catch (InvalidArgumentException) {
            return $this->notFound('Service not found.');
        }

        return DB::transaction(function () use ($request, $serviceUuid, $serviceIdBinary, $actor, $metadata): array {
            $service = DB::table('services')->where('id', $serviceIdBinary)->lockForUpdate()->first();

            if ($service === null) {
                return $this->notFound('Service not found.');
            }

            DB::table('services')->where('id', $serviceIdBinary)->update([
                'name' => $metadata['name'],
                'short_description' => $metadata['short_description'],
                'description' => $metadata['description'],
                'display_order' => $metadata['display_order'],
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_UPDATED',
                'SERVICE',
                $serviceUuid,
                ['name' => $metadata['name'], 'display_order' => $metadata['display_order']],
                ['name' => $service->name, 'display_order' => (int) $service->display_order],
            );

            $updated = AdminServicePresenter::loadForDetail($serviceIdBinary);

            return $this->ok(200, 'Service updated successfully.', ['service' => AdminServicePresenter::detail($updated)]);
        });
    }
}
