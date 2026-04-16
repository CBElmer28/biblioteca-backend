<?php

namespace App\Services;

// =============================================================================
// GlpiService — Capa Anticorrupción (ACL) hacia GLPI.
//
// Responsabilidades:
//   - Ser el ÚNICO punto de contacto con la API REST de GLPI.
//   - Traducir el modelo de dominio interno (tickets, comentarios, activos)
//     al modelo de GLPI y viceversa.
//   - Gestionar la sesión (session_token) con caché automático.
//   - Aislar al resto del ecosistema de los detalles del protocolo GLPI.
//
// El resto del sistema jamás conoce que existe GLPI.
// Solo conoce los DTOs/arrays que este servicio retorna.
// =============================================================================

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GlpiService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('glpi.url') . '/apirest.php';
    }

    // =========================================================================
    // GESTIÓN DE SESIÓN
    // =========================================================================

    private function getSessionToken(): string
    {
        return Cache::remember(
            'glpi_session_token',
            now()->addMinutes(config('glpi.session_ttl_minutes', 50)),
            function () {
                $response = Http::withHeaders([
                    'App-Token'     => config('glpi.app_token'),
                    'Authorization' => 'user_token ' . config('glpi.user_token'),
                    'Content-Type'  => 'application/json',
                ])->get("{$this->baseUrl}/initSession");

                if ($response->failed()) {
                    throw new \RuntimeException(
                        "GLPI: No se pudo iniciar sesión. Status: {$response->status()}"
                    );
                }

                $token = $response->json('session_token');

                if (!$token) {
                    throw new \RuntimeException('GLPI: session_token no recibido.');
                }

                return $token;
            }
        );
    }

    /** Construye el cliente HTTP base con los headers de autenticación */
    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'App-Token'     => config('glpi.app_token'),
            'Session-Token' => $this->getSessionToken(),
            'Content-Type'  => 'application/json',
        ])->timeout(15);
    }

    /**
     * Invalida el session_token cacheado y reintenta la llamada.
     * Llamar cuando GLPI responde 401.
     */
    private function refreshSessionAndRetry(callable $call): mixed
    {
        Cache::forget('glpi_session_token');
        return $call();
    }

    // =========================================================================
    // TICKETS
    // =========================================================================

    /**
     * Crear un ticket en GLPI.
     *
     * @param array{
     *   title: string,
     *   content: string,
     *   requester_email: string,
     *   urgency?: int,
     *   type?: int,
     *   category_id?: int|null,
     *   metadata?: array
     * } $data
     *
     * @return array{id: int, ticket_number: int}
     * @throws \RuntimeException
     */
    public function createTicket(array $data): array
    {
        $payload = [
            'input' => [
                'name'     => $data['title'],
                'content'  => $this->buildContent($data['content'], $data['metadata'] ?? []),
                'urgency'  => $data['urgency']     ?? config('glpi.urgency.normal'),
                'type'     => $data['type']         ?? config('glpi.ticket_type.request'),
                'status'   => 1,    // New
                '_users_id_requester' => $this->findOrCreateUser($data['requester_email']),
            ],
        ];

        if (!empty($data['category_id'])) {
            $payload['input']['itilcategories_id'] = $data['category_id'];
        }

        $response = $this->client()->post("{$this->baseUrl}/Ticket", $payload);

        if ($response->status() === 401) {
            return $this->refreshSessionAndRetry(fn() => $this->createTicket($data));
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "GLPI: Error al crear ticket. Status: {$response->status()} — " .
                $response->body()
            );
        }

        $result = $response->json();

        Log::info('GLPI: Ticket creado', ['id' => $result['id'] ?? null]);

        return [
            'id'            => $result['id'],
            'ticket_number' => $result['id'],  // GLPI usa el id como número
        ];
    }

    /**
     * Obtener un ticket por ID con sus datos normalizados.
     *
     * @throws \RuntimeException
     */
    public function getTicket(int $id): array
    {
        $response = $this->client()->get("{$this->baseUrl}/Ticket/{$id}");

        if ($response->status() === 401) {
            return $this->refreshSessionAndRetry(fn() => $this->getTicket($id));
        }

        if ($response->notFound()) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Ticket #{$id} no encontrado en GLPI."
            );
        }

        if ($response->failed()) {
            throw new \RuntimeException("GLPI: Error al obtener ticket #{$id}.");
        }

        return $this->normalizeTicket($response->json());
    }

    /**
     * Listar tickets de un usuario por email.
     * Retorna array normalizado listo para el frontend.
     */
    public function getTicketsByUser(string $email, int $limit = 50): array
    {
        $userId = $this->findOrCreateUser($email);

        $response = $this->client()->get("{$this->baseUrl}/Ticket", [
            'criteria'  => [
                ['field' => 4, 'searchtype' => 'equals', 'value' => $userId],
            ],
            'range'     => "0-{$limit}",
            'sort'      => 'date_mod',
            'order'     => 'DESC',
            'forcedisplay' => [1, 2, 3, 4, 12, 15, 17, 18],  // Campos esenciales
        ]);

        if ($response->status() === 401) {
            return $this->refreshSessionAndRetry(fn() => $this->getTicketsByUser($email, $limit));
        }

        if ($response->failed()) {
            Log::warning('GLPI: Error al listar tickets', ['email' => $email]);
            return [];
        }

        $tickets = $response->json();

        // GLPI retorna false cuando no hay resultados
        if (!is_array($tickets)) {
            return [];
        }

        return array_map(fn($t) => $this->normalizeTicket($t), $tickets);
    }

    // =========================================================================
    // FOLLOW-UPS (Comentarios)
    // =========================================================================

    /**
     * Agregar un comentario (follow-up) a un ticket existente.
     *
     * @throws \RuntimeException
     */
    public function addFollowUp(int $ticketId, string $content, bool $isPrivate = false): array
    {
        $response = $this->client()->post("{$this->baseUrl}/ITILFollowup", [
            'input' => [
                'items_id'   => $ticketId,
                'itemtype'   => 'Ticket',
                'content'    => $content,
                'is_private' => (int) $isPrivate,
            ],
        ]);

        if ($response->status() === 401) {
            return $this->refreshSessionAndRetry(fn() => $this->addFollowUp($ticketId, $content, $isPrivate));
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "GLPI: Error al agregar follow-up al ticket #{$ticketId}."
            );
        }

        return ['id' => $response->json('id'), 'ticket_id' => $ticketId];
    }

    /**
     * Obtener los follow-ups de un ticket.
     */
    public function getFollowUps(int $ticketId): array
    {
        $response = $this->client()->get("{$this->baseUrl}/Ticket/{$ticketId}/ITILFollowup");

        if ($response->status() === 401) {
            return $this->refreshSessionAndRetry(fn() => $this->getFollowUps($ticketId));
        }

        if ($response->failed() || !is_array($response->json())) {
            return [];
        }

        return array_map(fn($f) => [
            'id'         => $f['id'],
            'content'    => strip_tags($f['content'] ?? ''),
            'is_private' => (bool) ($f['is_private'] ?? false),
            'date'       => $f['date'] ?? null,
            'author_id'  => $f['users_id'] ?? null,
        ], $response->json());
    }

    // =========================================================================
    // ACTIVOS (Assets)
    // =========================================================================

    /**
     * Buscar activos en GLPI por nombre o número de serie.
     * Útil para vincular ejemplares físicos con activos de GLPI.
     */
    public function searchAssets(string $query, string $itemtype = 'Computer'): array
    {
        $response = $this->client()->get("{$this->baseUrl}/{$itemtype}", [
            'searchText' => ['name' => $query],
            'range'      => '0-20',
        ]);

        if ($response->status() === 401) {
            return $this->refreshSessionAndRetry(fn() => $this->searchAssets($query, $itemtype));
        }

        if ($response->failed() || !is_array($response->json())) {
            return [];
        }

        return array_map(fn($a) => [
            'id'          => $a['id'],
            'name'        => $a['name'] ?? '',
            'serial'      => $a['serial'] ?? null,
            'location_id' => $a['locations_id'] ?? null,
        ], $response->json());
    }

    // =========================================================================
    // USUARIOS
    // =========================================================================

    /**
     * Buscar usuario en GLPI por email. Si no existe, lo crea.
     * Cacheado por email para evitar llamadas repetidas en la misma request.
     */
    public function findOrCreateUser(string $email): int
    {
        return Cache::remember(
            "glpi_user_{$email}",
            now()->addHours(6),
            function () use ($email) {
                // Buscar usuario existente
                $search = $this->client()->get("{$this->baseUrl}/User", [
                    'searchText' => ['email' => $email],
                    'range'      => '0-1',
                ]);

                if ($search->ok() && is_array($search->json()) && !empty($search->json())) {
                    return (int) $search->json()[0]['id'];
                }

                // Crear usuario nuevo
                $create = $this->client()->post("{$this->baseUrl}/User", [
                    'input' => [
                        'name'     => explode('@', $email)[0],
                        'email'    => $email,
                        '_useremails' => [$email],
                    ],
                ]);

                if ($create->failed()) {
                    // Fallback: usar el usuario del sistema si no se puede crear
                    Log::warning("GLPI: No se pudo crear usuario para {$email}, usando sistema.");
                    return $this->getSystemUserId();
                }

                return (int) $create->json('id');
            }
        );
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    /** Normaliza un ticket de GLPI al formato interno del sistema */
    private function normalizeTicket(array $raw): array
    {
        $statusMap = config('glpi.status', []);

        return [
            'id'          => $raw['id'],
            'title'       => $raw['name'] ?? '',
            'content'     => strip_tags($raw['content'] ?? ''),
            'status'      => $statusMap[$raw['status'] ?? 1] ?? 'unknown',
            'status_code' => $raw['status'] ?? 1,
            'urgency'     => $raw['urgency'] ?? 3,
            'type'        => $raw['type'] ?? 1,
            'created_at'  => $raw['date'] ?? null,
            'updated_at'  => $raw['date_mod'] ?? null,
            'solved_at'   => $raw['solvedate'] ?? null,
            'closed_at'   => $raw['closedate'] ?? null,
        ];
    }

    /** Enriquece el contenido del ticket con metadata del dominio */
    private function buildContent(string $content, array $metadata): string
    {
        if (empty($metadata)) {
            return $content;
        }

        $metaBlock = "\n\n---\n**Metadata del sistema:**\n";
        foreach ($metadata as $key => $value) {
            $metaBlock .= "- **{$key}**: {$value}\n";
        }

        return $content . $metaBlock;
    }

    /** Obtiene el ID del usuario del sistema (para tickets automáticos) */
    private function getSystemUserId(): int
    {
        return (int) Cache::remember('glpi_system_user_id', now()->addDay(), function () {
            $email    = config('glpi.system_requester_email');
            $response = $this->client()->get("{$this->baseUrl}/User", [
                'searchText' => ['email' => $email],
                'range'      => '0-1',
            ]);

            if ($response->ok() && is_array($response->json()) && !empty($response->json())) {
                return (int) $response->json()[0]['id'];
            }

            return 1;  // Superadmin de GLPI como fallback final
        });
    }
}