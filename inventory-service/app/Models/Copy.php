<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Copy extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'book_id', 'copy_code', 'condition', 'status',
        'location', 'is_loanable', 'loan_count',
        'acquired_at', 'acquisition_cost', 'internal_notes', 'glpi_id',
    ];

    protected function casts(): array
    {
        return [
            'is_loanable'      => 'boolean',
            'loan_count'       => 'integer',
            'acquired_at'      => 'date',
            'acquisition_cost' => 'decimal:2',
        ];
    }

    // ── Guardia de integridad: rechazar si el libro padre es digital ──────────
    protected static function booted(): void
    {
        static::creating(function (Copy $copy): void {
            $book = Book::find($copy->book_id);

            if (!$book) {
                throw new \DomainException('El libro padre no existe.');
            }

            if ($book->is_digital) {
                throw new \DomainException(
                    "No se pueden crear ejemplares físicos para el e-book [{$book->title}]. " .
                    "Los e-books tienen licencia ilimitada y no requieren copias."
                );
            }
        });

        // Tras cualquier cambio de status, recalcular el libro padre
        static::saved(function (Copy $copy): void {
            if ($copy->wasChanged('status') || $copy->wasChanged('is_loanable')) {
                $copy->book->recalculateCopyCounts();
            }
        });

        static::deleted(function (Copy $copy): void {
            $copy->book->recalculateCopyCounts();
        });
    }

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function book(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function conditionLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CopyConditionLog::class)
                    ->orderBy('created_at', 'desc');
    }

    // ── Generador de copy_code ────────────────────────────────────────────────

    public static function generateCopyCode(): string
    {
        $seq = DB::selectOne(
            "SELECT nextval('inventory.copy_code_seq') AS val"
        )->val;

        return 'BIB-' . date('Y') . '-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    // ── Máquina de Estados ────────────────────────────────────────────────────

    // Grafo de transiciones válidas
    private const TRANSITIONS = [
        'available' => ['loaned', 'reserved', 'in_repair', 'withdrawn'],
        'loaned'    => ['available', 'withdrawn'],
        'reserved'  => ['available', 'loaned',  'withdrawn'],
        'in_repair' => ['available', 'withdrawn'],
        'withdrawn' => [],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Transicionar el ejemplar a un nuevo estado con auditoría completa.
     *
     * @throws \DomainException Si la transición no está permitida en el grafo.
     */
    public function transitionTo(
        string  $newStatus,
        ?string $newCondition  = null,
        ?string $changedBy     = null,
        ?string $changedByName = null,
        string  $context       = 'manual_inspection',
        ?string $loanId        = null,
        ?string $notes         = null
    ): void {
        if (!$this->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Transición inválida para ejemplar [{$this->copy_code}]: " .
                "{$this->status} → {$newStatus}. " .
                "Transiciones permitidas: " . implode(', ', self::TRANSITIONS[$this->status] ?? [])
            );
        }

        $oldStatus    = $this->status;
        $oldCondition = $this->condition;
        $updateData   = ['status' => $newStatus];

        if ($newCondition !== null && $newCondition !== $this->condition) {
            $updateData['condition'] = $newCondition;
        }

        if ($newStatus === 'loaned') {
            $updateData['loan_count'] = $this->loan_count + 1;
        }

        $this->update($updateData);

        // Registro de auditoría inmutable
        CopyConditionLog::create([
            'copy_id'          => $this->id,
            'from_condition'   => $oldCondition,
            'to_condition'     => $newCondition ?? $oldCondition,
            'from_status'      => $oldStatus,
            'to_status'        => $newStatus,
            'changed_by'       => $changedBy,
            'changed_by_name'  => $changedByName,
            'context'          => $context,
            'loan_id'          => $loanId,
            'notes'            => $notes,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->status === 'available' && $this->is_loanable;
    }

    public function isLost(): bool
    {
        return $this->condition === 'lost';
    }

    public function getReplacementValueAttribute(): float
    {
        // Si no tiene costo de adquisición registrado, usar tarifa plana como fallback
        return (float) ($this->acquisition_cost ?? config('library.default_replacement_cost', 50.00));
    }
}