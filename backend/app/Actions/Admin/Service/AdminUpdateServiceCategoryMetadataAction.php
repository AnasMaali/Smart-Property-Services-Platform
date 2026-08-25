<?php

namespace App\Actions\Admin\Service;

use App\Models\User;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Admin\AdminServiceCategoryPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Edits only the safe display metadata already represented in
 * `service_categories` (name, description, display_order) - a full
 * replace, not a partial patch, so a caller cannot accidentally leave a
 * stale value in place by omitting a field. `code` is deliberately never
 * editable here: nothing in this codebase reads a category's `code`
 * programmatically today, but it is the one stable identifier the public
 * catalog contract exposes, so renaming it is treated as out of scope for
 * a "safe metadata" edit.
 */
final class AdminUpdateServiceCategoryMetadataAction
{
    use BuildsCartResult;

    /**
     * @param  array{name: string, description: ?string, display_order: int}  $metadata
     */
    public function handle(Request $request, string $categoryId, User $actor, array $metadata): array
    {
        if (! ctype_digit($categoryId)) {
            return $this->notFound('Service category not found.');
        }

        return DB::transaction(function () use ($request, $categoryId, $actor, $metadata): array {
            $category = DB::table('service_categories')->where('id', (int) $categoryId)->lockForUpdate()->first();

            if ($category === null) {
                return $this->notFound('Service category not found.');
            }

            DB::table('service_categories')->where('id', $category->id)->update([
                'name' => $metadata['name'],
                'description' => $metadata['description'],
                'display_order' => $metadata['display_order'],
                'updated_at' => now(),
            ]);

            AdminAuditLogger::record(
                $request,
                $actor,
                'SERVICE_CATEGORY_UPDATED',
                'SERVICE_CATEGORY',
                (string) $category->id,
                ['name' => $metadata['name'], 'display_order' => $metadata['display_order']],
                ['name' => $category->name, 'display_order' => (int) $category->display_order],
            );

            $updated = DB::table('service_categories')->where('id', $category->id)->first();

            return $this->ok(200, 'Service category updated successfully.', ['service_category' => AdminServiceCategoryPresenter::detail($updated)]);
        });
    }
}
