<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class PasswordResetSession extends Model
{
    protected $table = 'password_reset_sessions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'otp_verification_id',
        'reset_token_hash',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'reset_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'id' => UuidBinaryCast::class,
            'otp_verification_id' => UuidBinaryCast::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
