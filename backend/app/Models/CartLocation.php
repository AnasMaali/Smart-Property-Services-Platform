<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class CartLocation extends Model
{
    protected $table = 'cart_locations';

    protected $primaryKey = 'cart_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'cart_id',
        'property_type_id',
        'area_id',
        'other_property_type_name',
        'street_name',
        'address_line',
        'building_name_or_number',
        'floor_number',
        'unit_number',
        'nearby_landmark',
        'additional_location_notes',
        'visit_contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'cart_id' => UuidBinaryCast::class,
            'property_type_id' => 'integer',
            'area_id' => 'integer',
        ];
    }
}
