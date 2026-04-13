<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'book_id', 'user_id', 'user_name',
        'rating', 'title', 'body',
        'is_verified_purchase', 'status',
        'rejection_reason', 'helpful_votes',
    ];

    protected function casts(): array
    {
        return [
            'is_verified_purchase' => 'boolean',
            'rating'               => 'integer',
            'helpful_votes'        => 'integer',
        ];
    }

    public function book(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    // Boot: recalcular rating del libro cuando se aprueba/rechaza una reseña
    protected static function booted(): void
    {
        static::saved(function (Review $review) {
            if ($review->wasChanged('status')) {
                $review->book->recalculateRating();
            }
        });

        static::deleted(function (Review $review) {
            $review->book->recalculateRating();
        });
    }
}