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

                // ── Dominio de inventario (Mirroring CRUD) ────────────────────

                // Catálogo (Libros)
                'book.created' => $this->handleBookCreated($payload),
                'book.updated' => $this->handleBookUpdated($payload),
                'book.deleted' => $this->handleBookDeleted($payload),

                // Ejemplares Físicos (Copias)
                'copy.created' => $this->handleCopyCreated($payload),
                'copy.updated' => $this->handleCopyUpdated($payload),
                'copy.deleted' => $this->handleCopyDeleted($payload),

                // Cambio de condición crítica en un ejemplar (crea ticket)
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


    private function handleCopyCreated(array $payload): string|array|null
    {
        $this->line("  └─ 📘 Nueva copia física detectada. Creando activo en GLPI...");

        try {
            $glpiService = app(\App\Services\GlpiService::class);
            
            // 1. Creamos la copia
            $result = $glpiService->createCopyAsset($payload);
            $copiaGlpiId = $result['id'];
            
            $this->line("  └─ ✅ Copia GLPI creada con ID: #{$copiaGlpiId}");

            // 2. VINCULAMOS la copia con el Libro Padre
            if (!empty($payload['book_glpi_id'])) {
                $glpiService->linkAssets(
                    config('glpi.book_itemtype'),       // Padre: Glpi\CustomAsset\LibrosAsset
                    $payload['book_glpi_id'],           // ID del Padre
                    "Glpi\\CustomAsset\\CopiaLibroAsset", // Hijo: La copia
                    $copiaGlpiId                        // ID del Hijo recién creado
                );
                $this->line("  └─ 🔗 Copia vinculada exitosamente al Catálogo #{$payload['book_glpi_id']}");
            }

            return $result;
        } catch (\Throwable $e) {
            $this->error("  └─ ❌ Error al crear copia en GLPI: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * copy.updated — Sincroniza cambios de estado (Ej: de Disponible a Prestado).
     */
    private function handleCopyUpdated(array $payload): string|array|null
    {
        if (empty($payload['glpi_id'])) {
            $this->error("  └─ ⚠️ Copia sin glpi_id. No se puede actualizar en GLPI.");
            return 'ignored';
        }

        $this->line("  └─ 🔄 Estado de copia actualizado. Sincronizando...");

        try {
            $glpiService = app(\App\Services\GlpiService::class);
            
            // Evaluamos si falló
            $exito = $glpiService->updateCopyStatus($payload['glpi_id'], $payload['status']);
            
            if (!$exito) {
                throw new \RuntimeException("GLPI devolvió error al intentar hacer el PUT.");
            }
            
            $this->line("  └─ ✅ Estado actualizado en GLPI.");
            
            // Retornamos 'ignored' en vez de un array con ID, 
            // para que la consola no imprima "Ticket creado: #X" por error.
            return 'ignored'; 
        } catch (\Throwable $e) {
            $this->error("  └─ ❌ Error al actualizar copia en GLPI: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * copy.deleted — Envía el activo a la papelera en GLPI.
     */
    private function handleCopyDeleted(array $payload): string|array|null
    {
        if (empty($payload['glpi_id'])) {
            return 'ignored';
        }

        $this->line("  └─ 🗑️ Copia eliminada. Borrando de GLPI...");

        try {
            $glpiService = app(\App\Services\GlpiService::class);
            $glpiService->deleteAsset(config('glpi.copy_itemtype'), $payload['glpi_id']);
            
            $this->line("  └─ ✅ Copia #{$payload['glpi_id']} enviada a la papelera en GLPI.");
            return ['id' => $payload['glpi_id']];
        } catch (\Throwable $e) {
            $this->error("  └─ ❌ Error al eliminar copia en GLPI: {$e->getMessage()}");
            return null;
        }
    }

    // =========================================================================
    // Handlers de Mirroring (Catálogo / Libros)
    // =========================================================================

        /**
     * book.created — Toma los datos del inventario y crea el activo en GLPI.
     */
    private function handleBookCreated(array $payload): string|array|null
    {
        $this->line("  └─ 📚 Nuevo libro detectado. Sincronizando como activo en GLPI...");

        try {
            $glpiService = app(\App\Services\GlpiService::class);
            $result = $glpiService->createBookAsset($payload);
            
            $this->line("  └─ ✅ Activo GLPI creado con ID: #{$result['id']}");
            return $result;
        } catch (\Throwable $e) {
            $this->error("  └─ ❌ Error al sincronizar activo en GLPI: {$e->getMessage()}");
            return null; // <-- Cambiado de 'failed' a null para evitar el error de lectura de array
        }
    }

    /**
     * book.updated — Actualiza los metadatos del libro en GLPI.
     */
    private function handleBookUpdated(array $payload): string|array|null
    {
        if (empty($payload['glpi_id'])) {
            $this->error("  └─ ⚠️ Libro sin glpi_id. Imposible actualizar en GLPI.");
            return 'ignored';
        }

        $this->line("  └─ 🔄 Libro actualizado. Sincronizando con GLPI...");

        try {
            $glpiService = app(\App\Services\GlpiService::class);
            
            // Re-empaquetamos los custom fields igual que al crear
            $customFields = json_encode([
                "45001" => $payload['title'] ?? 'Sin Título',
                "45002" => current($payload['authors'] ?? [])['name'] ?? 'Autor Desconocido',
                "45003" => $payload['isbn_13'] ?? $payload['isbn_10'] ?? '',
            ]);

            $glpiService->updateAsset(
                config('glpi.book_itemtype'), 
                $payload['glpi_id'], 
                [
                    'name' => $payload['title'],
                    'custom_fields' => $customFields
                ]
            );
            
            $this->line("  └─ ✅ Libro actualizado en GLPI.");
            return ['id' => $payload['glpi_id']];
        } catch (\Throwable $e) {
            $this->error("  └─ ❌ Error al actualizar libro en GLPI: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * book.deleted — Elimina el libro del catálogo de GLPI.
     */
    private function handleBookDeleted(array $payload): string|array|null
    {
        if (empty($payload['glpi_id'])) {
            return 'ignored';
        }

        $this->line("  └─ 🗑️ Libro eliminado. Borrando de GLPI...");

        try {
            $glpiService = app(\App\Services\GlpiService::class);
            $glpiService->deleteAsset(config('glpi.book_itemtype'), $payload['glpi_id']);
            
            $this->line("  └─ ✅ Libro #{$payload['glpi_id']} enviado a la papelera en GLPI.");
            return ['id' => $payload['glpi_id']];
        } catch (\Throwable $e) {
            $this->error("  └─ ❌ Error al eliminar libro en GLPI: {$e->getMessage()}");
            return null;
        }
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