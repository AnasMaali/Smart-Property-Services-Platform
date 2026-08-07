<?php

namespace App\Models;

use App\Casts\UuidBinaryCast;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'full_name',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => UuidBinaryCast::class,
        ];
    }
}
