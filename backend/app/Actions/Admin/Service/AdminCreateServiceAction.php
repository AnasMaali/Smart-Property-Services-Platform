<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServicePresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use App\Support\Uuid\UuidBinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B23 - creates one `services` row. Always inserted with
 * `is_active = 0` regardless of any client-supplied value - a brand-new
 * Service must never become bookable before its content/specialization/
 * pricing are configured (BLUE V1 catalog spec section 23: "Create Service
 * -> inactive -> configure -> activate"). App\Actions\Admin\Service\
 * AdminActivateServiceAction is the only way a Service ever becomes active,
 * and validates its own activation preconditions there.
 *
 * `code`/`slug` are immutable from creation onward - `slug` is the public
 * GET /v1/services/{slug} lookup key (see
 * AdminUpdateServiceMetadataAction's docblock), so both are validated for
 * uniqueness here exactly like AdminCreateServiceCategoryAction validates
 * `code`.
 */
final class AdminCreateServiceAction
{
    use BuildsCartResult;

    /**
     * @param  array{category_id: int, code: string, slug: string, name: string, short_description: ?string, description: ?string, display_order: int}  $data
     */
    public function handle(Request $request, User $actor, array $data): array
    {
        return DB::transaction(function () use ($request, $actor, $data): array {
            $errors = [];

            if (DB::table('services')->where('code', $data['code'])->exists()) {
                $errors['code'] = ['This service code is already in use.'];
            }

            if (DB::table('services')->where('slug', $data['slug'])->exists()) {
                $errors['slug'] = ['This service slug is already in use.'];
            }

            if (DB::table('services')->where('category_id', $data['category_id'])->where('name', $data['name'])->exists()) {
                $errors['name'] = ['A service with this name already exists in this category.'];
            }

            if ($errors !== []) {
                return $this->unprocessable('The given data was invalid.', $errors);
            }

            $serviceUuid = UuidBinary::generate();
            $now = now();

            DB::table('services')->insert([
                'id' => UuidBinary::toBinary($serviceUuid),
                'category_id' => $data['category_id'],
                'code' => $data['code'],
                'slug' => $data['slug'],
                'name' => $data['name'],
                'short_description' => $data['short_description'],
                'description' => $data['description'],
                'display_order' => $data['display_order'],
                'is_active' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CREATED',
                'SERVICE',
                $serviceUuid,
                ['code' => $data['code'], 'slug' => $data['slug'], 'name' => $data['name'], 'category_id' => $data['category_id']],
            );

            $created = AdminServicePresenter::loadForDetail(UuidBinary::toBinary($serviceUuid));

            return $this->ok(201, 'Service created successfully (inactive - configure it, then activate).', ['service' => AdminServicePresenter::detail($created)]);
        });
    }
}
