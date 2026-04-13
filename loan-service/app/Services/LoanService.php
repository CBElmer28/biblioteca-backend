<?php

namespace App\Services;

// =============================================================================
// LoanService — Orquesta el ciclo de vida completo de un préstamo:
//   create      → valida reglas, reserva el ejemplar en inventory-service,
//                 crea el registro y publica evento en Redis.
//   return      → registra devolución, evalúa daños, libera el ejemplar,
//                 genera multa si aplica (vía penalty-service).
//   renew       → extiende due_at respetando límites de renovación.
//   markOverdue → transición automática del scheduler.
// =============================================================================

use App\Models\Loan;
use App\Models\LoanRenewal;
use App\Models\LoanReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class LoanService
{
    // ── Crear préstamo ─────────────────────────────────────────────────────────

    /**
     * @throws \DomainException  Si el lector tiene multas pendientes, superó
     *                           el límite de préstamos o el ejemplar no está disponible.
     */
    public function create(
        array  $bookData,    // {id, title, isbn, author, is_digital, copy_id?, copy_code?, condition?}
        array  $userData,    // {id, name, email}
        array  $librarianData // {id, name}
    ): Loan {
        // ── Regla 1: verificar multas pendientes del lector ───────────────────
        $this->assertNoActivePenalties($userData['id']);

        // ── Regla 2: verificar límite de préstamos activos ────────────────────
        $activeCount = Loan::byUser($userData['id'])->active()->count();
        if ($activeCount >= config('library.loans.max_active_per_user')) {
            throw new \DomainException(
                "El lector ya tiene {$activeCount} préstamo(s) activo(s). " .
                "Límite máximo: " . config('library.loans.max_active_per_user') . "."
            );
        }

        // ── Regla 3: para físicos, verificar disponibilidad del ejemplar ──────
        if (!$bookData['is_digital']) {
            if (empty($bookData['copy_id'])) {
                throw new \DomainException('Debe especificarse el ID del ejemplar físico.');
            }
            $this->assertCopyAvailable($bookData['copy_id']);
        }

        DB::beginTransaction();
        try {
            $isDigital = (bool) $bookData['is_digital'];
            $loanDays  = $isDigital
                ? config('library.loans.ebook_max_days')
                : config('library.loans.max_days');

            $loan = Loan::create([
                'copy_id'       => $bookData['copy_id'] ?? null,
                'book_id'       => $bookData['id'],
                'is_digital'    => $isDigital,
                'book_title'    => $bookData['title'],
                'book_isbn'     => $bookData['isbn'] ?? null,
                'book_author'   => $bookData['author'] ?? null,
                'copy_code'     => $bookData['copy_code'] ?? null,

                'user_id'       => $userData['id'],
                'user_name'     => $userData['name'],
                'user_email'    => $userData['email'],

                'issued_by'     => $librarianData['id'],
                'issued_by_name'=> $librarianData['name'],

                'loaned_at'     => now(),
                'due_at'        => now()->addDays($loanDays),
                'status'        => 'active',

                'condition_out' => $bookData['condition'] ?? null,
                'renewals_count'=> 0,
                'max_renewals'  => config('library.loans.max_renewals'),
            ]);

            // Para libros físicos: notificar al inventory-service para cambiar
            // el status del ejemplar a "loaned"
            if (!$isDigital) {
                $this->notifyInventoryTransition($bookData['copy_id'], [
                    'status'          => 'loaned',
                    'loan_id'         => $loan->id,
                    'changed_by'      => $librarianData['id'],
                    'changed_by_name' => $librarianData['name'],
                    'notes'           => "Préstamo {$loan->id} creado.",
                ]);
            }

            // Cancelar reserva del lector si la tenía
            LoanReservation::where('book_id', $bookData['id'])
                ->where('user_id', $userData['id'])
                ->whereIn('status', ['waiting', 'notified'])
                ->update(['status' => 'fulfilled']);

            DB::commit();

            $this->publishEvent('loan.created', [
                'loan_id'      => $loan->id,
                'user_email'   => $userData['email'],
                'user_name'    => $userData['name'],
                'book_title'   => $bookData['title'],
                'copy_code'    => $bookData['copy_code'] ?? null,
                'is_digital'   => $isDigital,
                'loaned_at'    => $loan->loaned_at->toIso8601String(),
                'due_at'       => $loan->due_at->toIso8601String(),
            ]);

            return $loan->load('renewals');

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ── Registrar devolución ──────────────────────────────────────────────────

    /**
     * @throws \DomainException  Si el préstamo ya está cerrado.
     */
    public function return(
        Loan   $loan,
        string $conditionIn,
        string $librarianId,
        string $librarianName,
        ?string $returnNotes = null
    ): Loan {
        if ($loan->isClosed()) {
            throw new \DomainException(
                "El préstamo [{$loan->id}] ya está cerrado con estado '{$loan->status}'."
            );
        }

        DB::beginTransaction();
        try {
            $wasOverdue  = $loan->isOverdue();
            $daysOverdue = $loan->days_overdue;
            $newStatus   = 'returned';

            $loan->update([
                'status'       => $newStatus,
                'returned_at'  => now(),
                'condition_in' => $conditionIn,
                'return_notes' => $returnNotes,
            ]);

            // Liberar el ejemplar físico en inventory-service
            if (!$loan->is_digital && $loan->copy_id) {
                $this->notifyInventoryTransition($loan->copy_id, [
                    'status'          => 'available',
                    'condition'       => $conditionIn,
                    'loan_id'         => $loan->id,
                    'changed_by'      => $librarianId,
                    'changed_by_name' => $librarianName,
                    'notes'           => "Devuelto con condición: {$conditionIn}.",
                ]);
            }

            // Generar multa si hay retraso o daño en libros físicos
            if (!$loan->is_digital) {
                $this->maybeGeneratePenalty($loan, $wasOverdue, $daysOverdue, $conditionIn, $librarianId);
            }

            // Notificar al siguiente en cola de reservas
            $this->notifyNextInQueue($loan->book_id);

            DB::commit();

            $this->publishEvent('loan.returned', [
                'loan_id'      => $loan->id,
                'user_email'   => $loan->user_email,
                'user_name'    => $loan->user_name,
                'book_title'   => $loan->book_title,
                'days_overdue' => $daysOverdue,
                'had_damage'   => $loan->hasDamage(),
            ]);

            return $loan->fresh('renewals');

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ── Renovar préstamo ──────────────────────────────────────────────────────

    /**
     * @throws \DomainException  Si el préstamo no puede renovarse.
     */
    public function renew(
        Loan   $loan,
        string $requestedById,
        string $requestedByName,
        string $requestedByRole,
        ?string $notes = null
    ): Loan {
        if (!$loan->canRenew()) {
            $reason = match(true) {
                $loan->isClosed()   => "El préstamo ya está cerrado.",
                $loan->isOverdue()  => "No se puede renovar un préstamo vencido.",
                default             => "Se alcanzó el límite de {$loan->max_renewals} renovación(es).",
            };
            throw new \DomainException($reason);
        }

        // Verificar que el lector no tenga multas pendientes
        if ($requestedByRole === 'lector') {
            $this->assertNoActivePenalties($requestedById);
        }

        DB::beginTransaction();
        try {
            $previousDueAt = $loan->due_at;
            $newDueAt      = $loan->due_at->addDays(config('library.loans.renewal_days'));

            $loan->update([
                'due_at'         => $newDueAt,
                'renewals_count' => $loan->renewals_count + 1,
            ]);

            LoanRenewal::create([
                'loan_id'            => $loan->id,
                'renewal_number'     => $loan->renewals_count,
                'previous_due_at'    => $previousDueAt,
                'new_due_at'         => $newDueAt,
                'requested_by'       => $requestedById,
                'requested_by_name'  => $requestedByName,
                'requested_by_role'  => $requestedByRole,
                'notes'              => $notes,
            ]);

            DB::commit();

            $this->publishEvent('loan.renewed', [
                'loan_id'         => $loan->id,
                'user_email'      => $loan->user_email,
                'user_name'       => $loan->user_name,
                'book_title'      => $loan->book_title,
                'new_due_at'      => $newDueAt->toIso8601String(),
                'renewals_left'   => $loan->fresh()->remaining_renewals,
            ]);

            return $loan->fresh('renewals');

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ── Marcar como perdido ───────────────────────────────────────────────────

    public function markAsLost(
        Loan   $loan,
        string $librarianId,
        string $librarianName,
        ?string $notes = null
    ): Loan {
        if ($loan->isClosed()) {
            throw new \DomainException("El préstamo ya está cerrado.");
        }

        DB::beginTransaction();
        try {
            $loan->update(['status' => 'lost', 'return_notes' => $notes]);

            // Marcar el ejemplar como perdido en inventory-service
            if (!$loan->is_digital && $loan->copy_id) {
                $this->notifyInventoryTransition($loan->copy_id, [
                    'status'          => 'withdrawn',
                    'condition'       => 'lost',
                    'loan_id'         => $loan->id,
                    'changed_by'      => $librarianId,
                    'changed_by_name' => $librarianName,
                    'notes'           => "Ejemplar declarado perdido.",
                ]);
            }

            // Multa por pérdida (costo de reposición)
            $this->generateLossPenalty($loan, $librarianId);

            DB::commit();

            $this->publishEvent('loan.lost', [
                'loan_id'    => $loan->id,
                'user_email' => $loan->user_email,
                'user_name'  => $loan->user_name,
                'book_title' => $loan->book_title,
            ]);

            return $loan->fresh();

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ── Marcar vencidos (llamado por el scheduler) ────────────────────────────

    public function markExpiredLoans(): int
    {
        $expired = Loan::expiredAndNotMarked()->get();

        foreach ($expired as $loan) {
            $loan->update(['status' => 'overdue']);

            $this->publishEvent('loan.overdue', [
                'loan_id'    => $loan->id,
                'user_email' => $loan->user_email,
                'user_name'  => $loan->user_name,
                'book_title' => $loan->book_title,
                'due_at'     => $loan->due_at->toIso8601String(),
                'days_overdue' => $loan->days_overdue,
            ]);
        }

        return $expired->count();
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function assertNoActivePenalties(string $userId): void
    {
        $response = Http::timeout(5)
            ->get(config('services.penalty.url') . "/api/v1/internal/penalties/check/{$userId}", [
                'status' => 'pending',
            ]);

        if ($response->successful() && $response->json('has_pending')) {
            throw new \DomainException(
                'El lector tiene multas pendientes. Debe regularizarlas antes de solicitar un nuevo préstamo.'
            );
        }
    }

    private function assertCopyAvailable(string $copyId): void
    {
        $response = Http::timeout(5)
            ->get(config('services.inventory.url') . "/api/v1/internal/copies/{$copyId}/availability");

        if ($response->failed()) {
            throw new \RuntimeException('No se pudo verificar la disponibilidad del ejemplar.');
        }

        if (!$response->json('is_available')) {
            throw new \DomainException(
                "El ejemplar solicitado no está disponible. Estado actual: " .
                $response->json('status')
            );
        }
    }

    private function notifyInventoryTransition(string $copyId, array $data): void
    {
        $response = Http::timeout(10)
            ->withHeaders(['X-Internal-Secret' => config('app.internal_secret')])
            ->post(
                config('services.inventory.url') . "/api/v1/internal/copies/{$copyId}/transition",
                $data
            );

        if ($response->failed()) {
            Log::error('LoanService: inventory transition falló', [
                'copy_id'  => $copyId,
                'data'     => $data,
                'response' => $response->body(),
            ]);
            throw new \RuntimeException('No se pudo actualizar el estado del ejemplar en el inventario.');
        }
    }

    private function maybeGeneratePenalty(
        Loan   $loan,
        bool   $wasOverdue,
        int    $daysOverdue,
        string $conditionIn,
        string $librarianId
    ): void {
        $hasDamage  = $loan->hasDamage();
        $hasOverdue = $wasOverdue && $daysOverdue > 0;

        if (!$hasDamage && !$hasOverdue) {
            return;
        }

        $payload = [
            'loan_id'         => $loan->id,
            'user_id'         => $loan->user_id,
            'user_name'       => $loan->user_name,
            'user_email'      => $loan->user_email,
            'book_title'      => $loan->book_title,
            'copy_code'       => $loan->copy_code,
            'days_overdue'    => $daysOverdue,
            'condition_out'   => $loan->condition_out,
            'condition_in'    => $conditionIn,
            'has_damage'      => $hasDamage,
            'generated_by'    => $librarianId,
        ];

        $response = Http::timeout(10)
            ->withHeaders(['X-Internal-Secret' => config('app.internal_secret')])
            ->post(config('services.penalty.url') . '/api/v1/internal/penalties/generate', $payload);

        if ($response->successful()) {
            // Guardar referencia a la multa en el préstamo
            $loan->update(['penalty_id' => $response->json('data.id')]);
        } else {
            Log::warning('LoanService: no se pudo generar multa automática', [
                'loan_id' => $loan->id,
                'response' => $response->body(),
            ]);
        }
    }

    private function generateLossPenalty(Loan $loan, string $librarianId): void
    {
        $response = Http::timeout(10)
            ->withHeaders(['X-Internal-Secret' => config('app.internal_secret')])
            ->post(config('services.penalty.url') . '/api/v1/internal/penalties/generate', [
                'loan_id'       => $loan->id,
                'user_id'       => $loan->user_id,
                'user_name'     => $loan->user_name,
                'user_email'    => $loan->user_email,
                'book_title'    => $loan->book_title,
                'copy_code'     => $loan->copy_code,
                'type'          => 'loss',
                'generated_by'  => $librarianId,
            ]);

        if ($response->successful()) {
            $loan->update(['penalty_id' => $response->json('data.id')]);
        }
    }

    private function notifyNextInQueue(string $bookId): void
    {
        $next = LoanReservation::where('book_id', $bookId)
            ->waiting()
            ->first();

        if (!$next) return;

        $next->update([
            'status'       => 'notified',
            'notified_at'  => now(),
            'expires_at'   => now()->addHours(48),
        ]);

        $this->publishEvent('reservation.available', [
            'reservation_id' => $next->id,
            'user_email'     => $next->user_email,
            'user_name'      => $next->user_name,
            'book_title'     => $next->book_title,
            'expires_at'     => $next->expires_at->toIso8601String(),
        ]);
    }

    private function publishEvent(string $event, array $payload): void
    {
        try {
            Redis::publish('libreria.events', json_encode([
                'event'     => $event,
                'payload'   => $payload,
                'timestamp' => now()->toIso8601String(),
                'source'    => 'loan-service',
            ]));
        } catch (\Throwable $e) {
            Log::warning("Redis publish falló [{$event}]: " . $e->getMessage());
        }
    }
}