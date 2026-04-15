<?php

namespace App\Services;

// =============================================================================
// PenaltyService — Orquesta el ciclo de vida completo de una multa:
//   generate      → recibe datos del loan-service, calcula y persiste
//   pay           → registra pagos (totales o parciales) con auditoría
//   waive         → condonación total con validación de rol
//   getStatus     → responde al loan-service si el lector tiene multas activas
// =============================================================================

use App\Models\Penalty;
use App\Models\PenaltyPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PenaltyService
{
    public function __construct(
        private readonly PenaltyCalculatorService $calculator
    ) {}

    // ── Generar multa (llamado internamente por loan-service) ─────────────────

    /**
     * @throws \DomainException  Si ya existe una multa para el préstamo.
     * @throws \InvalidArgumentException Si el monto calculado es cero.
     */
    public function generate(array $loanData): Penalty
    {
        // Idempotencia: el loan-service puede reintentar en caso de fallo
        $existing = Penalty::where('loan_id', $loanData['loan_id'])->first();
        if ($existing) {
            return $existing;
        }

        $breakdown = $this->calculator->calculate($loanData);

        if ($breakdown['total_amount'] <= 0) {
            throw new \InvalidArgumentException(
                "El cálculo resultó en monto cero. No se genera multa para loan [{$loanData['loan_id']}]."
            );
        }

        DB::beginTransaction();
        try {
            $penalty = Penalty::create([
                'loan_id'            => $loanData['loan_id'],
                'user_id'            => $loanData['user_id'],
                'user_name'          => $loanData['user_name'],
                'user_email'         => $loanData['user_email'],
                'book_title'         => $loanData['book_title'],
                'copy_code'          => $loanData['copy_code'] ?? null,

                'type'               => $breakdown['type'],
                'days_overdue'       => $breakdown['days_overdue'],
                'overdue_amount'     => $breakdown['overdue_amount'],
                'damage_amount'      => $breakdown['damage_amount'],
                'loss_amount'        => $breakdown['loss_amount'],
                'total_amount'       => $breakdown['total_amount'],
                'amount_paid'        => 0.00,

                'status'             => 'pending',
                'generated_by'       => $loanData['generated_by'] ?? null,
                'generated_by_name'  => $loanData['generated_by_name'] ?? 'Sistema',
                'notes'              => $loanData['notes'] ?? null,
            ]);

            DB::commit();

            $this->publishEvent('penalty.generated', [
                'penalty_id'    => $penalty->id,
                'user_email'    => $penalty->user_email,
                'user_name'     => $penalty->user_name,
                'book_title'    => $penalty->book_title,
                'type'          => $penalty->type,
                'total_amount'  => $penalty->total_amount,
                'breakdown'     => [
                    'days_overdue'   => $penalty->days_overdue,
                    'overdue_amount' => $penalty->overdue_amount,
                    'damage_amount'  => $penalty->damage_amount,
                    'loss_amount'    => $penalty->loss_amount,
                ],
            ]);

            return $penalty;

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('PenaltyService: error al generar multa', [
                'loan_id' => $loanData['loan_id'],
                'error'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ── Registrar pago ────────────────────────────────────────────────────────

    /**
     * @throws \DomainException  Si la multa no acepta pagos o el monto es inválido.
     */
    public function pay(
        Penalty $penalty,
        float   $amount,
        string  $paymentMethod,
        string  $receivedById,
        string  $receivedByName,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): PenaltyPayment {
        if (!$penalty->canReceivePayment()) {
            throw new \DomainException(
                "La multa [{$penalty->id}] ya está {$penalty->status} y no acepta más pagos."
            );
        }

        $remaining = $penalty->remaining_amount;

        if ($amount <= 0) {
            throw new \DomainException('El monto del pago debe ser mayor a cero.');
        }

        if ($amount > $remaining) {
            throw new \DomainException(
                "El monto del pago (S/ {$amount}) supera el saldo pendiente (S/ {$remaining})."
            );
        }

        DB::beginTransaction();
        try {
            $balanceBefore = $remaining;
            $balanceAfter  = round($remaining - $amount, 2);

            $payment = PenaltyPayment::create([
                'penalty_id'       => $penalty->id,
                'amount'           => $amount,
                'payment_method'   => $paymentMethod,
                'reference_number' => $referenceNumber,
                'notes'            => $notes,
                'received_by'      => $receivedById,
                'received_by_name' => $receivedByName,
                'balance_before'   => $balanceBefore,
                'balance_after'    => $balanceAfter,
            ]);

            // Actualizar monto pagado y estado
            $newAmountPaid = round((float) $penalty->amount_paid + $amount, 2);
            $newStatus     = $balanceAfter <= 0.00 ? 'paid' : 'partial';

            $penalty->update([
                'amount_paid' => $newAmountPaid,
                'status'      => $newStatus,
            ]);

            DB::commit();

            if ($newStatus === 'paid') {
                $this->publishEvent('penalty.paid', [
                    'penalty_id' => $penalty->id,
                    'user_email' => $penalty->user_email,
                    'user_name'  => $penalty->user_name,
                    'book_title' => $penalty->book_title,
                    'total_paid' => $newAmountPaid,
                ]);
            }

            return $payment;

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ── Condonar multa (solo admin) ───────────────────────────────────────────

    /**
     * @throws \DomainException  Si la multa ya está cerrada.
     */
    public function waive(
        Penalty $penalty,
        string  $waivedById,
        string  $waivedByName,
        string  $reason
    ): Penalty {
        if ($penalty->isClosed()) {
            throw new \DomainException(
                "La multa [{$penalty->id}] ya está cerrada con estado '{$penalty->status}'."
            );
        }

        $penalty->update([
            'status'           => 'waived',
            'waived_by'        => $waivedById,
            'waived_by_name'   => $waivedByName,
            'waive_reason'     => $reason,
            'waived_at'        => now(),
        ]);

        $this->publishEvent('penalty.waived', [
            'penalty_id'  => $penalty->id,
            'user_email'  => $penalty->user_email,
            'user_name'   => $penalty->user_name,
            'book_title'  => $penalty->book_title,
            'waived_by'   => $waivedByName,
            'reason'      => $reason,
            'amount'      => $penalty->remaining_amount,
        ]);

        return $penalty->fresh('payments');
    }

    // ── Verificar si el lector tiene multas activas (usado por loan-service) ──

    public function userHasPendingPenalties(string $userId): bool
    {
        return Penalty::byUser($userId)->pending()->exists();
    }

    public function getUserPendingSummary(string $userId): array
    {
        $penalties = Penalty::byUser($userId)->pending()->get();

        return [
            'has_pending'   => $penalties->isNotEmpty(),
            'count'         => $penalties->count(),
            'total_pending' => $penalties->sum('amount_pending'),
            'penalties'     => $penalties->map(fn($p) => [
                'id'             => $p->id,
                'type'           => $p->type,
                'type_label'     => config("penalties.types.{$p->type}"),
                'total_amount'   => $p->total_amount,
                'amount_pending' => $p->amount_pending,
                'book_title'     => $p->book_title,
                'created_at'     => $p->created_at->toIso8601String(),
            ]),
        ];
    }

    // ── Estadísticas para el dashboard ───────────────────────────────────────

    public function getStats(): array
    {
        return [
            'total_pending_count'  => Penalty::pending()->count(),
            'total_pending_amount' => Penalty::pending()->sum('amount_pending'),
            'total_collected'      => PenaltyPayment::sum('amount'),
            'total_waived_amount'  => Penalty::waived()->sum('total_amount'),
            'by_type' => Penalty::selectRaw('type, COUNT(*) as count, SUM(total_amount) as total')
                ->groupBy('type')
                ->get()
                ->mapWithKeys(fn($r) => [$r->type => [
                    'count' => $r->count,
                    'total' => $r->total,
                ]]),
            'collected_this_month' => PenaltyPayment::whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('amount'),
        ];
    }

    private function publishEvent(string $event, array $payload): void
    {
        try {
            Redis::publish('libreria.events', json_encode([
                'event'     => $event,
                'payload'   => $payload,
                'timestamp' => now()->toIso8601String(),
                'source'    => 'penalty-service',
            ]));
        } catch (\Throwable $e) {
            Log::warning("Redis publish falló [{$event}]: " . $e->getMessage());
        }
    }
}