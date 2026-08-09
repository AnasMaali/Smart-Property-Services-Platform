<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The deterministic area -> zone mapping: `area_id` is this table's primary
 * key, so the schema itself enforces that one area maps to at most one
 * service zone in BLUE V1 (see database/blue_v1_schema.sql).
 */
class ServiceZoneArea extends Model
{
    protected $table = 'service_zone_areas';

    protected $primaryKey = 'area_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'area_id',
        'service_zone_id',
    ];

    protected function casts(): array
    {
        return [
            'area_id' => 'integer',
            'service_zone_id' => 'integer',
        ];
    }
}
