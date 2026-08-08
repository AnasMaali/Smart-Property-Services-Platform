<?php

namespace App\Actions\ReferenceData;

use Illuminate\Support\Facades\DB;

class GetRegistrationReferenceDataAction
{
    /**
     * Active-only lookup data needed to render the registration and
     * edit-profile dropdowns: cities (each with its active areas nested),
     * property relationship types, and service categories.
     *
     * @return array{
     *     cities: array<int, array{id: int, code: string, name: string, areas: array<int, array{id: int, code: string, name: string}>}>,
     *     property_relationship_types: array<int, array{id: int, code: string, name: string, description: ?string}>,
     *     service_categories: array<int, array{id: int, code: string, name: string, description: ?string}>,
     * }
     */
    public function handle(): array
    {
        $areasByCity = DB::table('areas')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'city_id', 'code', 'name'])
            ->groupBy('city_id');

        $cities = DB::table('cities')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'code', 'name'])
            ->map(fn ($city) => [
                'id' => $city->id,
                'code' => $city->code,
                'name' => $city->name,
                'areas' => ($areasByCity->get($city->id) ?? collect())
                    ->map(fn ($area) => [
                        'id' => $area->id,
                        'code' => $area->code,
                        'name' => $area->name,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        $propertyRelationshipTypes = DB::table('property_relationship_types')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'code', 'name', 'description'])
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();

        $serviceCategories = DB::table('service_categories')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->get(['id', 'code', 'name', 'description'])
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();

        return [
            'cities' => $cities,
            'property_relationship_types' => $propertyRelationshipTypes,
            'service_categories' => $serviceCategories,
        ];
    }
}
