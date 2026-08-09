<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class AppointmentSlot extends Model
{
    protected $table = 'appointment_slots';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'starts_at',
        'ends_at',
        'booking_capacity',
        'time_window_id',
        'is_active',
        'internal_note',
    ];

    protected function casts(): array
    {
        return [
            'id' => UuidBinaryCast::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'booking_capacity' => 'integer',
            'time_window_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
