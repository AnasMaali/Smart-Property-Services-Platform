<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    protected $table = 'otp_verifications';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'user_id',
        'purpose_id',
        'status_id',
        'target_phone_number',
        'code_hash',
        'failed_attempt_count',
        'max_attempts',
        'expires_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'id' => UuidBinaryCast::class,
            'user_id' => UuidBinaryCast::class,
            'purpose_id' => 'integer',
            'status_id' => 'integer',
            'failed_attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }
}
