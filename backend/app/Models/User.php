<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'id',
        'phone_number',
        'email',
        'password_hash',
        'account_status_id',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'id' => UuidBinaryCast::class,
            'account_status_id' => 'integer',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
