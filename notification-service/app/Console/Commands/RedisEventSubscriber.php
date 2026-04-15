<?php

namespace App\Console\Commands;

// =============================================================================
// RedisEventSubscriber — Daemon permanente que escucha el canal PubSub.
// Por cada mensaje recibido, despacha un ProcessNotificationJob al queue worker.
// El subscriber NO procesa emails directamente — solo despacha y sigue escuchando.
//
// Proceso 1: php artisan events:subscribe  (este comando)
// Proceso 2: php artisan queue:work redis --queue=notifications
// =============================================================================

use App\Jobs\ProcessNotificationJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RedisEventSubscriber extends Command
{
    protected $signature   = 'events:subscribe';
    protected $description = 'Escuchar el canal Redis de eventos del sistema bibliotecario';

    public function handle(): void
    {
        $channel = config('app.redis_events_channel', 'libreria.events');

        $this->info("🔔 Suscrito al canal: [{$channel}]");
        $this->info('Esperando eventos del sistema bibliotecario... (Ctrl+C para detener)');
        $this->newLine();

        Redis::subscribe([$channel], function (string $raw) {
            $this->processMessage($raw);
        });
    }

    private function processMessage(string $raw): void
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            $eventType     = $data['event']     ?? null;
            $payload       = $data['payload']   ?? [];
            $sourceService = $data['source']    ?? 'unknown';
            $timestamp     = $data['timestamp'] ?? now()->toIso8601String();

            if (!$eventType) {
                Log::warning('RedisSubscriber: mensaje sin event_type', ['raw' => $raw]);
                return;
            }

            $this->line(
                "[{$timestamp}] <comment>{$eventType}</comment> " .
                "← <info>{$sourceService}</info>"
            );

            ProcessNotificationJob::dispatch($eventType, $payload, $sourceService);

            $this->line("  └─ Job despachado ✓");

        } catch (\JsonException $e) {
            Log::error('RedisSubscriber: JSON inválido', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('RedisSubscriber: error inesperado', ['error' => $e->getMessage()]);
        }
    }
}