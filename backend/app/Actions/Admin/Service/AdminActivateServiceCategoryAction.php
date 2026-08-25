<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServiceCategoryPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Activating a Category makes it (and its already-active Services) appear
 * again in GET /v1/service-categories and GET /v1/service-categories/
 * {category}/services - both filter on `is_active = 1` today (see
 * App\Actions\ServiceCatalog\ListServiceCategoriesAction/
 * ListCategoryServicesAction). Already-active is a safe idempotent no-op:
 * no audit row is written when nothing actually changes.
 */
final class AdminActivateServiceCategoryAction
{
    use BuildsCartResult;

    public function handle(Request $request, string $categoryId, User $actor): array
    {
        if (! ctype_digit($categoryId)) {
            return $this->notFound('Service category not found.');
        }

        return DB::transaction(function () use ($request, $categoryId, $actor): array {
            $category = DB::table('service_categories')->where('id', (int) $categoryId)->lockForUpdate()->first();

            if ($category === null) {
                return $this->notFound('Service category not found.');
            }

            if ((int) $category->is_active === 1) {
                return $this->ok(200, 'Service category is already active.', ['service_category' => AdminServiceCategoryPresenter::detail($category)]);
            }

            DB::table('service_categories')->where('id', $category->id)->update(['is_active' => 1, 'updated_at' => now()]);

            AdminAuditLogger::record($request, $actor, 'SERVICE_CATEGORY_ACTIVATED', 'SERVICE_CATEGORY', (string) $category->id);

            $updated = DB::table('service_categories')->where('id', $category->id)->first();

            return $this->ok(200, 'Service category activated successfully.', ['service_category' => AdminServiceCategoryPresenter::detail($updated)]);
        });
    }
}
