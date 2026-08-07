<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    protected $table = 'customer_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'property_relationship_type_id',
        'area_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => UuidBinaryCast::class,
            'property_relationship_type_id' => 'integer',
            'area_id' => 'integer',
        ];
    }
}
