<?php

namespace App\Support\Admin;

use App\Support\Uuid\UuidBinary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLUE V1 Phase B8. `service_categories.id` is a plain unsigned int, not a
 * binary(16) UUID (see database/blue_v1_schema.sql) - this is the same
 * identifier the existing public GET /v1/service-categories endpoint
 * already returns, so it is presented here as-is (never re-encoded), just
 * like App\Actions\ServiceCatalog\ListServiceCategoriesAction.
 */
final class AdminServiceCategoryPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function presentList(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $categoryIds = $rows->pluck('id')->all();

        $servicesCounts = DB::table('services')
            ->whereIn('category_id', $categoryIds)
            ->selectRaw('category_id, COUNT(*) as services_count')
            ->groupBy('category_id')
            ->pluck('services_count', 'category_id');

        return $rows->map(fn (object $row) => [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'description' => $row->description,
            'display_order' => (int) $row->display_order,
            'is_active' => (bool) $row->is_active,
            'services_count' => (int) ($servicesCounts->get($row->id) ?? 0),
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(object $row): array
    {
        $services = DB::table('services')
            ->where('category_id', $row->id)
            ->orderBy('display_order')
            ->get(['id', 'code', 'name', 'is_active', 'display_order']);

        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'description' => $row->description,
            'display_order' => (int) $row->display_order,
            'is_active' => (bool) $row->is_active,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(),
            'services' => $services->map(fn (object $service) => [
                'uuid' => UuidBinary::toString($service->id),
                'code' => $service->code,
                'name' => $service->name,
                'is_active' => (bool) $service->is_active,
                'display_order' => (int) $service->display_order,
            ])->values()->all(),
        ];
    }
}
