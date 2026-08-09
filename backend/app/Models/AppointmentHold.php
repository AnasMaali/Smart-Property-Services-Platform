<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class AppointmentHold extends Model
{
    protected $table = 'appointment_holds';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'cart_id',
        'appointment_slot_id',
        'held_at',
        'expires_at',
        'released_at',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => UuidBinaryCast::class,
            'cart_id' => UuidBinaryCast::class,
            'appointment_slot_id' => UuidBinaryCast::class,
            'held_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }
}
