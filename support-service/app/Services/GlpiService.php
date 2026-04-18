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
        $this->baseUrl    = rtrim(config('glpi.url'), '/');
        $this->appToken   = config('glpi.app_token');
        $this->userToken  = config('glpi.user_token');
        $this->sessionTtl = config('glpi.session_ttl', 3000);
    }

    // =========================================================================
    // GESTIÓN DE SESIÓN
    // =========================================================================

    public function getSessionToken(): ?string
    {
        return Cache::remember('glpi_session_token', $this->sessionTtl, function () {
            try {
                $response = Http::withHeaders([
                    'App-Token'     => $this->appToken,
                    'Authorization' => "user_token {$this->userToken}",
                    'Content-Type'  => 'application/json',
                ])->get("{$this->baseUrl}/initSession");

                if ($response->successful()) {
                    return $response->json('session_token');
                }

                Log::error('GLPI initSession failed', ['response' => $response->body()]);
                return null;
            } catch (\Exception $e) {
                Log::error('GLPI connection error', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    public function invalidateSession(): void
    {
        Cache::forget('glpi_session_token');
    }

    protected function authHeaders(): array
    {
        return [
            'App-Token'     => $this->appToken,
            'Session-Token' => $this->getSessionToken() ?? '',
            'Content-Type'  => 'application/json',
        ];
    }

    protected function request(string $method, string $url, array $options = [])
    {
        $doRequest = function () use ($method, $url, $options) {
            $http = Http::withHeaders($this->authHeaders());
            return match ($method) {
                'GET'    => $http->get($url, $options['query'] ?? []),
                'POST'   => $http->post($url, $options['json'] ?? []),
                'PUT'    => $http->put($url, $options['json'] ?? []),
                'DELETE' => $http->delete($url, $options['json'] ?? []),
            };
        };

        $response = $doRequest();

        if ($response->status() === 401) {
            $this->invalidateSession();
            $response = $doRequest();
        }

        return $response;
    }

    public function ping(): bool
    {
        return $this->getSessionToken() !== null;
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

    /**
     * Crear un Libro (Activo Personalizado) en GLPI.
     * Mapeo sugerido de IDs en GLPI: 1:Título, 2:Autor, 3:ISBN
     */
    public function createBookAsset(array $bookData): array
    {
        $itemtype = config('glpi.book_itemtype');
        
        // Extraer nombres de relaciones (autores y categorías)
        $autores = collect($bookData['authors'] ?? [])->pluck('name')->implode(', ');
        $categorias = collect($bookData['categories'] ?? [])->pluck('name')->implode(', ');

        $customFields = json_encode([
            "1" => $autores ?: 'Sin Autor',
            "2" => $categorias ?: 'Sin Categoría',
            "3" => $bookData['publication_year'] ?? '',
            "4" => $bookData['language'] ?? 'es',
            "5" => ($bookData['is_digital'] ?? false) ? 'Digital' : 'Físico',
        ]);

        $payload = [
            'input' => [
                'name'          => $bookData['title'] ?? 'Nuevo Libro',
                'custom_fields' => $customFields,
                'comment'       => "ISBN: " . ($bookData['isbn_13'] ?? 'N/A') . " | Sincronizado vía Evento Redis",
            ],
        ];

        $response = $this->request('POST', "{$this->baseUrl}/{$itemtype}", ['json' => $payload]);

        if (!$response->successful()) {
            throw new \RuntimeException("GLPI: Error al crear Libro. Status: {$response->status()} — " . $response->body());
        }

        return $response->json();
    }

    public function createCopyAsset(array $copyData): array
    {
        $itemtype = config('glpi.copy_itemtype');
        
        $customFields = json_encode([
            "1" => $copyData['condition'] ?? 'new',
            "2" => $copyData['location'] ?? 'Sin asignar',
            "3" => ($copyData['is_loanable'] ?? true) ? 'Sí' : 'No',
            "4" => $copyData['acquired_at'] ?? '',
            "5" => $copyData['acquisition_cost'] ?? '0.00',
        ]);

        $payload = [
            'input' => [
                'name'          => $copyData['copy_code'],
                'serial'        => $copyData['copy_code'], 
                'states_id'     => $this->mapStatusToGlpi($copyData['status'] ?? 'available'),
                'custom_fields' => $customFields,
                'comment'       => $copyData['internal_notes'] ?? "Copia física del libro.",
            ],
        ];

        $response = $this->request('POST', "{$this->baseUrl}/{$itemtype}", ['json' => $payload]);

        if (!$response->successful()) {
            throw new \RuntimeException("GLPI: Error al crear Copia. Status: {$response->status()} — " . $response->body());
        }

        return $response->json();
    }

    public function updateAsset(string $itemtype, int $glpiId, array $input): bool
    {
        $payload = [
            'input' => array_merge(['id' => $glpiId], $input)
        ];

        $response = $this->request('PUT', "{$this->baseUrl}/{$itemtype}/{$glpiId}", ['json' => $payload]);

        if (!$response->successful()) {
            Log::error("GLPI: Error al actualizar {$itemtype} #{$glpiId}", ['body' => $response->body()]);
        }

        return $response->successful();
    }

    public function deleteAsset(string $itemtype, int $glpiId, bool $forcePurge = false): bool
    {
        $url = "{$this->baseUrl}/{$itemtype}/{$glpiId}";
        if ($forcePurge) {
            $url .= "?force_purge=true";
        }

        $response = $this->request('DELETE', $url);

        return $response->successful();
    }

    public function linkAssets(string $itemtype1, int $itemsId1, string $itemtype2, int $itemsId2): bool
    {
        $payload = [
            'input' => [
                'itemtype1' => $itemtype1,
                'items_id1' => $itemsId1,
                'itemtype2' => $itemtype2,
                'items_id2' => $itemsId2,
            ]
        ];

        $response = $this->request('POST', "{$this->baseUrl}/Item_Item", ['json' => $payload]);

        if (!$response->successful()) {
            Log::error("GLPI: Error al vincular activos", ['body' => $response->body()]);
        }

        return $response->successful();
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

    public function updateCopyStatus(int $glpiId, string $newStatus): bool
    {
        return $this->updateAsset(
            config('glpi.copy_itemtype'), 
            $glpiId, 
            ['states_id' => $this->mapStatusToGlpi($newStatus)]
        );
    }

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

    private function mapStatusToGlpi(string $status): int
    {
        $map = [
            'available' => 1, // Ej: Disponible
            'loaned'    => 2, // Ej: Prestado
            'damaged'   => 3, // Ej: En Mantenimiento
            'lost'      => 4, // Ej: Extraviado
        ];

        return $map[$status] ?? 1; // Por defecto lo pone como Disponible
    }
}