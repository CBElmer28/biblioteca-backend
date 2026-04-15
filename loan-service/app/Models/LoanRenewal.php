<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LoanRenewal extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'loan_id', 'renewal_number',
        'previous_due_at', 'new_due_at',
        'requested_by', 'requested_by_name', 'requested_by_role',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'previous_due_at' => 'datetime',
            'new_due_at'      => 'datetime',
            'created_at'      => 'datetime',
        ];
    }

    public function loan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}