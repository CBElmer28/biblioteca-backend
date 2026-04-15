<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LoanReservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'book_id', 'book_title',
        'user_id', 'user_name', 'user_email',
        'status', 'queue_position',
        'notified_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'expires_at'  => 'datetime',
        ];
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', 'waiting')->orderBy('queue_position');
    }

    public function isExpired(): bool
    {
        return $this->status === 'notified'
            && $this->expires_at
            && $this->expires_at->isPast();
    }
}