<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'cart_id',
        'service_id',
        'quantity',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'id' => UuidBinaryCast::class,
            'cart_id' => UuidBinaryCast::class,
            'service_id' => UuidBinaryCast::class,
            'quantity' => 'integer',
            'display_order' => 'integer',
        ];
    }
}
