<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServiceCategoryPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B23 - creates one `service_categories` row. `code` is the
 * one stable identifier the public catalog contract exposes (see
 * App\Actions\Admin\Service\AdminUpdateServiceCategoryMetadataAction's
 * docblock) - immutable once created, exactly like it is already
 * immutable once created for editing. A duplicate `code` is rejected as a
 * plain validation error rather than surfacing the underlying unique-key
 * constraint violation.
 */
final class AdminCreateServiceCategoryAction
{
    use BuildsCartResult;

    /**
     * @param  array{code: string, name: string, description: ?string, display_order: int, is_active: bool}  $data
     */
    public function handle(Request $request, User $actor, array $data): array
    {
        return DB::transaction(function () use ($request, $actor, $data): array {
            if (DB::table('service_categories')->where('code', $data['code'])->exists()) {
                return $this->unprocessable('The given data was invalid.', ['code' => ['This category code is already in use.']]);
            }

            $now = now();

            $categoryId = DB::table('service_categories')->insertGetId([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'],
                'display_order' => $data['display_order'],
                'is_active' => $data['is_active'] ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CATEGORY_CREATED',
                'SERVICE_CATEGORY',
                (string) $categoryId,
                ['code' => $data['code'], 'name' => $data['name'], 'is_active' => $data['is_active']],
            );

            $created = DB::table('service_categories')->where('id', $categoryId)->first();

            return $this->ok(201, 'Service category created successfully.', ['service_category' => AdminServiceCategoryPresenter::detail($created)]);
        });
    }
}
