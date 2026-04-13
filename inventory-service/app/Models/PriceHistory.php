<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'book_id', 'old_price', 'new_price',
        'old_discount', 'new_discount',
        'changed_by_user_id', 'reason', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_price'    => 'decimal:2',
            'new_price'    => 'decimal:2',
            'changed_at'   => 'datetime',
        ];
    }

    public function book(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}