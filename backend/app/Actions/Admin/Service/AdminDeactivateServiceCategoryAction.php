<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServiceCategoryPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Deactivating a Category removes it from GET /v1/service-categories and
 * makes GET /v1/service-categories/{category}/services 404 (both filter on
 * `is_active = 1`) - it does NOT deactivate its individual Services, and
 * does NOT retroactively affect a Service's own by-slug detail page (GET
 * /v1/services/{slug} only checks the Service's own `is_active`, never its
 * category's - see App\Actions\ServiceCatalog\GetServiceDetailsAction).
 * This is pre-existing behavior, not introduced by this Action - no
 * cascading deactivation was invented here. Already-inactive is a safe
 * idempotent no-op: no audit row is written when nothing actually changes.
 */
final class AdminDeactivateServiceCategoryAction
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

            if ((int) $category->is_active === 0) {
                return $this->ok(200, 'Service category is already inactive.', ['service_category' => AdminServiceCategoryPresenter::detail($category)]);
            }

            DB::table('service_categories')->where('id', $category->id)->update(['is_active' => 0, 'updated_at' => now()]);

            AdminAuditLogger::record($request, $actor, 'SERVICE_CATEGORY_DEACTIVATED', 'SERVICE_CATEGORY', (string) $category->id);

            $updated = DB::table('service_categories')->where('id', $category->id)->first();

            return $this->ok(200, 'Service category deactivated successfully.', ['service_category' => AdminServiceCategoryPresenter::detail($updated)]);
        });
    }
}
