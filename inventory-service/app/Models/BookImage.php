<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookImage extends Model
{
    protected $fillable = ['book_id', 'url', 'alt_text', 'type', 'is_primary', 'sort_order'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function book(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}