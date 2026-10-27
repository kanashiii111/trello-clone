<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class User extends Model {
    protected $table = 'users';

    protected $primaryKey = 'user_id';

    public $timestamps = false;

    protected $fillable = ['email', 'password_hash', 'name'];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'user_id'          => 'int',
        'created_datetime' => 'datetime',
        'updated_datetime' => 'datetime',
    ];

    public function boards(): HasMany {
        return $this->hasMany(Board::class, 'owner_user_id', 'user_id');
    }
}
