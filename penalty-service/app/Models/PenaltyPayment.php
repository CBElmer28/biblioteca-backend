<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PenaltyPayment extends Model
{
    use HasUuids;

    // Inmutable — solo inserts
    public $timestamps = false;

    protected $fillable = [
        'penalty_id', 'amount', 'payment_method',
        'reference_number', 'notes',
        'received_by', 'received_by_name',
        'balance_before', 'balance_after',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after'  => 'decimal:2',
            'paid_at'        => 'datetime',
        ];
    }

    public function penalty(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Penalty::class);
    }
}