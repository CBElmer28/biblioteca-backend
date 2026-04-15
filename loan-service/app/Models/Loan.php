<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Loan extends Model
{
    use HasUuids;

    protected $fillable = [
        'copy_id', 'book_id', 'is_digital',
        'book_title', 'book_isbn', 'book_author', 'copy_code',
        'user_id', 'user_name', 'user_email',
        'issued_by', 'issued_by_name',
        'loaned_at', 'due_at', 'returned_at',
        'status',
        'condition_out', 'condition_in', 'return_notes',
        'renewals_count', 'max_renewals',
        'penalty_id',
    ];

    protected function casts(): array
    {
        return [
            'is_digital'      => 'boolean',
            'loaned_at'       => 'datetime',
            'due_at'          => 'datetime',
            'returned_at'     => 'datetime',
            'renewals_count'  => 'integer',
            'max_renewals'    => 'integer',
        ];
    }

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function renewals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LoanRenewal::class)->orderBy('renewal_number');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'overdue');
    }

    public function scopeByUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDueWithinDays(Builder $query, int $days): Builder
    {
        return $query->where('status', 'active')
                     ->whereBetween('due_at', [now(), now()->addDays($days)]);
    }

    // Para el scheduler: préstamos vencidos que aún figuran como "active"
    public function scopeExpiredAndNotMarked(Builder $query): Builder
    {
        return $query->where('status', 'active')
                     ->where('due_at', '<', now());
    }

    // ── Máquina de estados ────────────────────────────────────────────────────

    private const TRANSITIONS = [
        'active'   => ['returned', 'overdue', 'lost'],
        'overdue'  => ['returned', 'lost'],
        'returned' => [],
        'lost'     => [],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    // ── Reglas de negocio ─────────────────────────────────────────────────────

    public function isActive(): bool    { return $this->status === 'active'; }
    public function isOverdue(): bool   { return $this->status === 'overdue'; }
    public function isReturned(): bool  { return $this->status === 'returned'; }
    public function isLost(): bool      { return $this->status === 'lost'; }
    public function isClosed(): bool    { return in_array($this->status, ['returned', 'lost'], true); }

    public function canRenew(): bool
    {
        return $this->isActive()
            && !$this->isOverdue()
            && $this->renewals_count < $this->max_renewals;
    }

    public function getRemainingRenewalsAttribute(): int
    {
        return max(0, $this->max_renewals - $this->renewals_count);
    }

    public function getDaysOverdueAttribute(): int
    {
        if (!$this->isOverdue() && !($this->isActive() && $this->due_at->isPast())) {
            return 0;
        }

        $reference = $this->returned_at ?? now();
        return (int) $this->due_at->diffInDays($reference);
    }

    public function getDaysUntilDueAttribute(): int
    {
        if ($this->isClosed() || $this->due_at->isPast()) {
            return 0;
        }

        return (int) now()->diffInDays($this->due_at);
    }

    /** Si el lector devuelve con peor condición, hay daño que penalizar */
    public function hasDamage(): bool
    {
        if (!$this->condition_out || !$this->condition_in) {
            return false;
        }

        $scale = ['new' => 4, 'good' => 3, 'worn' => 2, 'damaged' => 1, 'lost' => 0];

        return ($scale[$this->condition_in] ?? 0) < ($scale[$this->condition_out] ?? 0);
    }
}