<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Note extends Model
{
    protected $table = 'notes';
    protected $guarded = [];

    public $timestamps = false;

    protected $fillable = ['title', 'content'];
}