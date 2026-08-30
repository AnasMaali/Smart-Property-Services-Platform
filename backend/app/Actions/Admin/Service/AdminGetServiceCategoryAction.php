<?php

namespace App\Actions\Admin\Service;

use App\Support\Admin\AdminServiceCategoryPresenter;
use App\Support\Cart\Concerns\BuildsCartResult;
use Illuminate\Support\Facades\DB;

final class AdminGetServiceCategoryAction
{
    use BuildsCartResult;

    public function handle(string $categoryId): array
    {
        if (! ctype_digit($categoryId)) {
            return $this->notFound('Service category not found.');
        }

        $category = DB::table('service_categories')->where('id', (int) $categoryId)->first();

        if ($category === null) {
            return $this->notFound('Service category not found.');
        }

        return $this->ok(200, 'Service category retrieved successfully.', [
            'service_category' => AdminServiceCategoryPresenter::detail($category),
        ]);
    }
}
