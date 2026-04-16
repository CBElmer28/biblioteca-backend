<?php

namespace App\Services;

// =============================================================================
// TicketDomainService — Traduce eventos de dominio del ecosistema bibliotecario
// a tickets de soporte en GLPI.
// =============================================================================

class TicketDomainService
{
    public function __construct(private readonly GlpiService $glpi) {}

    // ── Libro dañado durante un préstamo ──────────────────────────────────────

    public function createBookDamagedTicket(array $payload): ?array
    {
        // 1. Resolvemos las variables antes de la interpolación
        $bookTitle    = $payload['book_title'] ?? 'Desconocido';
        $copyCode     = $payload['copy_code'] ?? 'N/A';
        $conditionOut = $payload['condition_out'] ?? '—';
        $conditionIn  = $payload['condition_in'] ?? '—';
        $userName     = $payload['user_name'] ?? '—';
        $userEmail    = $payload['user_email'] ?? '—';
        $loanId       = $payload['loan_id'] ?? '—';

        // 2. Construimos el texto con variables limpias
        $title   = "Daño en ejemplar: {$bookTitle} [{$copyCode}]";
        $content = <<<TEXT
Se ha detectado daño en un ejemplar físico durante la devolución de un préstamo.

**Libro:** {$bookTitle}
**Código de ejemplar:** {$copyCode}
**Condición al salir:** {$conditionOut}
**Condición al entrar:** {$conditionIn}
**Lector:** {$userName} ({$userEmail})
**ID del préstamo:** {$loanId}

Se requiere inspección física del ejemplar y evaluación de reparación o baja.
TEXT;

        return $this->glpi->createTicket([
            'title'           => $title,
            'content'         => $content,
            'requester_email' => config('glpi.system_requester_email'),
            'urgency'         => config('glpi.urgency.normal'),
            'type'            => config('glpi.ticket_type.incident'),
            'category_id'     => config('glpi.categories.book_damage'),
            'metadata'        => [
                'copy_code'     => $payload['copy_code'] ?? null,
                'loan_id'       => $payload['loan_id'] ?? null,
                'condition_out' => $payload['condition_out'] ?? null,
                'condition_in'  => $payload['condition_in'] ?? null,
            ],
        ]);
    }

    // ── Multa crítica — monto elevado ─────────────────────────────────────────

    public function createCriticalPenaltyTicket(array $payload): ?array
    {
        $amount     = number_format($payload['total_amount'] ?? 0, 2);
        $userName   = $payload['user_name'] ?? 'Lector';
        $userEmail  = $payload['user_email'] ?? '—';
        $type       = $payload['type'] ?? '—';
        $bookTitle  = $payload['book_title'] ?? '—';
        $penaltyId  = $payload['penalty_id'] ?? '—';

        $title   = "Multa crítica S/ {$amount} — {$userName}";
        $content = <<<TEXT
Se ha generado una multa de alto valor que requiere atención del personal.

**Lector:** {$userName} ({$userEmail})
**Monto total:** S/ {$amount}
**Tipo:** {$type}
**Libro:** {$bookTitle}
**ID de multa:** {$penaltyId}

Se recomienda contactar al lector y gestionar un plan de pago si es necesario.
TEXT;

        return $this->glpi->createTicket([
            'title'           => $title,
            'content'         => $content,
            'requester_email' => config('glpi.system_requester_email'),
            'urgency'         => config('glpi.urgency.high'),
            'type'            => config('glpi.ticket_type.incident'),
            'category_id'     => config('glpi.categories.penalty_critical'),
            'metadata'        => [
                'penalty_id'   => $payload['penalty_id'] ?? null,
                'user_id'      => $payload['user_id'] ?? null,
                'total_amount' => $payload['total_amount'] ?? null,
            ],
        ]);
    }

    // ── Ejemplar declarado perdido ────────────────────────────────────────────

    public function createBookLostTicket(array $payload): ?array
    {
        $bookTitle = $payload['book_title'] ?? '—';
        $copyCode  = $payload['copy_code'] ?? 'No registrado';
        $userName  = $payload['user_name'] ?? '—';
        $userEmail = $payload['user_email'] ?? '—';
        $loanId    = $payload['loan_id'] ?? '—';

        $title   = "Ejemplar perdido: {$bookTitle} [{$copyCode}]";
        $content = <<<TEXT
Un ejemplar físico ha sido declarado como perdido.

**Libro:** {$bookTitle}
**Código de ejemplar:** {$copyCode}
**Último lector:** {$userName} ({$userEmail})
**ID del préstamo:** {$loanId}

Acciones recomendadas:
1. Verificar con el lector el paradero del ejemplar.
2. Proceder con la baja del inventario si corresponde.
3. Confirmar cobro de multa por pérdida.
TEXT;

        return $this->glpi->createTicket([
            'title'           => $title,
            'content'         => $content,
            'requester_email' => config('glpi.system_requester_email'),
            'urgency'         => config('glpi.urgency.high'),
            'type'            => config('glpi.ticket_type.incident'),
            'category_id'     => config('glpi.categories.book_damage'),
            'metadata'        => [
                'copy_code' => $payload['copy_code'] ?? null,
                'loan_id'   => $payload['loan_id'] ?? null,
                'user_id'   => $payload['user_id'] ?? null,
            ],
        ]);
    }

    // ── Disputa de préstamo iniciada por lector ───────────────────────────────

    public function createLoanDisputeTicket(array $payload): ?array
    {
        $loanId    = $payload['loan_id'] ?? 'N/A';
        $userName  = $payload['user_name'] ?? '—';
        $userEmail = $payload['user_email'] ?? '—';
        $bookTitle = $payload['book_title'] ?? '—';
        $reason    = $payload['reason'] ?? 'No especificado';

        $title   = "Disputa de préstamo #{$loanId} — {$userName}";
        $content = <<<TEXT
Un lector ha iniciado una disputa sobre su préstamo.

**Lector:** {$userName} ({$userEmail})
**ID del préstamo:** {$loanId}
**Libro:** {$bookTitle}
**Motivo:** {$reason}
TEXT;

        return $this->glpi->createTicket([
            'title'           => $title,
            'content'         => $content,
            'requester_email' => $payload['user_email'] ?? config('glpi.system_requester_email'),
            'urgency'         => config('glpi.urgency.normal'),
            'type'            => config('glpi.ticket_type.request'),
            'category_id'     => config('glpi.categories.loan_dispute'),
            'metadata'        => [
                'loan_id' => $payload['loan_id'] ?? null,
                'user_id' => $payload['user_id'] ?? null,
            ],
        ]);
    }
}