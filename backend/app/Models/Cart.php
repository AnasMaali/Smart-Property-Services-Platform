<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'carts';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'customer_user_id',
        'status_id',
        'currency_id',
        'last_activity_at',
        'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => UuidBinaryCast::class,
            'customer_user_id' => UuidBinaryCast::class,
            'status_id' => 'integer',
            'currency_id' => 'integer',
            'last_activity_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }
}
