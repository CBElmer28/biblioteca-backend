<?php

namespace App\Console\Commands;

// =============================================================================
// RedisEventSubscriber — Daemon de escucha del canal Redis del ecosistema.
//
// Flujo:
//   Redis PubSub → este comando → TicketDomainService → GlpiService → GLPI
//
// El resto del ecosistema publica eventos con semántica de DOMINIO
// (book.damaged, loan.lost, penalty.critical). Este servicio es el ÚNICO
// que sabe que esos eventos deben generar tickets en GLPI.
// Los otros microservicios nunca sabrán que GLPI existe.
// =============================================================================

use App\Services\TicketDomainService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RedisEventSubscriber extends Command
{
    protected $signature   = 'events:subscribe';
    protected $description = 'Escuchar eventos del ecosistema y generar tickets en GLPI cuando corresponda';

    public function __construct(private readonly TicketDomainService $ticketService)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $channel = config('app.redis_events_channel', 'libreria.events');

        $this->info("🎫 support-service suscrito al canal: [{$channel}]");
        $this->info('Esperando eventos relevantes... (Ctrl+C para detener)');
        $this->newLine();

        // SUBSCRIBE es bloqueante — el proceso queda vivo permanentemente
        Redis::subscribe([$channel], function (string $raw) {
            $this->processEvent($raw);
        });
    }

    // =========================================================================
    // Procesamiento de eventos
    // =========================================================================

    private function processEvent(string $raw): void
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            $eventType     = $data['event']     ?? null;
            $payload       = $data['payload']   ?? [];
            $sourceService = $data['source']    ?? 'unknown';
            $timestamp     = $data['timestamp'] ?? now()->toIso8601String();

            if (!$eventType) {
                return;
            }

            $this->line("[{$timestamp}] <fg=gray>{$sourceService}</> → <comment>{$eventType}</comment>");

            // ── Router de eventos ─────────────────────────────────────────────
            // Solo actúa sobre eventos que requieren intervención de soporte.
            // El resto se ignora silenciosamente.
            $result = match($eventType) {

                // ── Dominio de préstamos ──────────────────────────────────────

                // Ejemplar devuelto con daño físico detectado
                'loan.returned' => $this->handleLoanReturned($payload),

                // Ejemplar declarado perdido por el bibliotecario
                'loan.lost'     => $this->handleLoanLost($payload),

                // ── Dominio de multas ─────────────────────────────────────────

                // Multa generada con monto elevado (umbral configurable)
                'penalty.generated' => $this->handlePenaltyGenerated($payload),

                // ── Dominio de inventario ─────────────────────────────────────

                // Cambio de condición crítica en un ejemplar (manual)
                'copy.damaged'  => $this->handleCopyDamaged($payload),

                // ── Eventos que NO requieren ticket de soporte ────────────────
                'user.registered',
                'loan.created',
                'loan.renewed',
                'loan.due_soon',
                'loan.overdue',
                'penalty.paid',
                'penalty.waived',
                'reservation.available' => 'ignored',

                // Evento desconocido — loguear y continuar
                default => $this->handleUnknownEvent($eventType, $payload),
            };

            if ($result && $result !== 'ignored') {
                $this->line("  └─ ✅ Ticket GLPI creado: #{$result['id']}");
            } elseif ($result === 'ignored') {
                $this->line("  └─ <fg=gray>Ignorado (sin acción de soporte)</>");;
            }

        } catch (\JsonException $e) {
            Log::error('support-service: JSON inválido recibido', [
                'error' => $e->getMessage(),
                'raw'   => substr($raw, 0, 200),
            ]);
        } catch (\Throwable $e) {
            Log::error('support-service: error procesando evento', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error("  └─ ❌ Error: {$e->getMessage()}");
        }
    }

    // =========================================================================
    // Handlers por evento
    // =========================================================================

    /**
     * loan.returned — Solo actúa si la devolución detectó daño físico.
     */
    private function handleLoanReturned(array $payload): string|array
    {
        // Ignorar si no hubo daño
        if (empty($payload['had_damage'])) {
            return 'ignored';
        }

        $this->line("  └─ 🔴 Daño detectado en devolución. Creando ticket...");

        return $this->ticketService->createBookDamagedTicket($payload)
            ?? $this->fallback('loan.returned', $payload);
    }

    /**
     * loan.lost — Siempre genera ticket (ejemplar físico fuera de inventario).
     */
    private function handleLoanLost(array $payload): string|array
    {
        $this->line("  └─ 🔴 Ejemplar perdido. Creando ticket...");

        return $this->ticketService->createBookLostTicket($payload)
            ?? $this->fallback('loan.lost', $payload);
    }

    /**
     * penalty.generated — Solo actúa si el monto supera el umbral crítico.
     * Umbral: S/ 50.00 (equivale a pérdida de un libro de costo medio).
     */
    private function handlePenaltyGenerated(array $payload): string|array
    {
        $criticalThreshold = 50.00;
        $totalAmount       = (float) ($payload['total_amount'] ?? 0);

        if ($totalAmount < $criticalThreshold) {
            return 'ignored';  // Multas pequeñas no requieren soporte manual
        }

        $this->line("  └─ 💰 Multa crítica S/ {$totalAmount}. Creando ticket...");

        return $this->ticketService->createCriticalPenaltyTicket(
            array_merge($payload, ['penalty_id' => $payload['penalty_id'] ?? null])
        ) ?? $this->fallback('penalty.generated', $payload);
    }

    /**
     * copy.damaged — Cambio manual de condición a 'damaged' por bibliotecario.
     */
    private function handleCopyDamaged(array $payload): string|array
    {
        // Solo escalar si el nuevo estado es 'damaged' (no 'worn' — es normal)
        if (($payload['to_condition'] ?? '') !== 'damaged') {
            return 'ignored';
        }

        $this->line("  └─ 📖 Ejemplar marcado como dañado. Creando ticket...");

        return $this->ticketService->createBookDamagedTicket($payload)
            ?? $this->fallback('copy.damaged', $payload);
    }

    /**
     * Evento no mapeado — loguear para análisis futuro.
     */
    private function handleUnknownEvent(string $eventType, array $payload): string
    {
        Log::debug("support-service: evento no mapeado [{$eventType}]", [
            'payload_keys' => array_keys($payload),
        ]);

        return 'ignored';
    }

    /**
     * Fallback cuando el servicio GLPI no puede crear el ticket.
     * Loguea para intervención manual.
     */
    private function fallback(string $event, array $payload): string
    {
        Log::critical("support-service: GLPI no pudo crear ticket para [{$event}]", [
            'payload' => $payload,
        ]);

        $this->error("  └─ ❌ GLPI no respondió. Evento logueado para revisión manual.");

        return 'failed';
    }
}