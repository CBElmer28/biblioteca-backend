<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Penalty extends Model
{
    use HasUuids;

    protected $fillable = [
        'loan_id', 'user_id', 'user_name', 'user_email',
        'book_title', 'copy_code', 'type',
        'days_overdue', 'overdue_amount', 'damage_amount', 'loss_amount',
        'total_amount', 'amount_paid',
        'status',
        'waived_by', 'waived_by_name', 'waive_reason', 'waived_at',
        'generated_by', 'generated_by_name',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'overdue_amount' => 'decimal:2',
            'damage_amount'  => 'decimal:2',
            'loss_amount'    => 'decimal:2',
            'total_amount'   => 'decimal:2',
            'amount_paid'    => 'decimal:2',
            'amount_pending' => 'decimal:2',
            'days_overdue'   => 'integer',
            'waived_at'      => 'datetime',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PenaltyPayment::class)->orderBy('paid_at');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'partial']);
    }

    public function scopeByUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    public function scopeWaived(Builder $query): Builder
    {
        return $query->where('status', 'waived');
    }

    // ── Helpers de estado ─────────────────────────────────────────────────────

    public function isPending(): bool  { return $this->status === 'pending'; }
    public function isPartial(): bool  { return $this->status === 'partial'; }
    public function isPaid(): bool     { return $this->status === 'paid'; }
    public function isWaived(): bool   { return $this->status === 'waived'; }
    public function isClosed(): bool   { return in_array($this->status, ['paid', 'waived'], true); }

    public function canReceivePayment(): bool
    {
        return in_array($this->status, ['pending', 'partial'], true);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0.0, (float) $this->total_amount - (float) $this->amount_paid);
    }

    public function getPaymentProgressAttribute(): float
    {
        if ((float) $this->total_amount === 0.0) return 100.0;
        return round(((float) $this->amount_paid / (float) $this->total_amount) * 100, 2);
    }
}