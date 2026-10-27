<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Card extends Model {
    protected $table = 'cards';

    protected $primaryKey = 'card_id';

    public $timestamps = false;

    protected $fillable = ['board_id', 'column_id', 'sort_index', 'title', 'content', 'color', 'is_archived'];

    protected $casts = [
        'card_id'          => 'int',
        'board_id'         => 'int',
        'column_id'        => 'int',
        'sort_index'       => 'int',
        'is_archived'      => 'bool',
        'created_datetime' => 'datetime',
        'updated_datetime' => 'datetime',
    ];

    public function board(): BelongsTo {
        return $this->belongsTo(Board::class, 'board_id', 'board_id');
    }

    public function column(): BelongsTo {
        return $this->belongsTo(BoardColumn::class, 'column_id', 'column_id');
    }
}
