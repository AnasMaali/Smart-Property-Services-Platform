<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentTimeWindow extends Model
{
    protected $table = 'appointment_time_windows';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'description',
        'start_time',
        'end_time',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
