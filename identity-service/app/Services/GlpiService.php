<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GlpiService
{
    private string $baseUrl;
    private string $appToken;
    private string $userToken;

    public function __construct()
    {
        $this->baseUrl   = rtrim(config('services.glpi.url'), '/') . '/apirest.php';
        $this->appToken  = config('services.glpi.app_token');
        $this->userToken = config('services.glpi.user_token');
    }

    // -------------------------------------------------------------------------
    // AUTENTICACIÓN
    // -------------------------------------------------------------------------
    private function getSessionToken(): string
    {
        return Cache::remember('glpi_session_token', now()->addMinutes(50), function () {
            $response = Http::withHeaders([
                'App-Token'     => $this->appToken,
                'Authorization' => "user_token {$this->userToken}",
                'Content-Type'  => 'application/json',
            ])->get("{$this->baseUrl}/initSession");

            if ($response->failed()) {
                Log::error('GLPI: No se pudo iniciar sesión', ['status' => $response->status()]);
                throw new \RuntimeException('No se pudo conectar con el sistema de soporte.');
            }

            return $response->json('session_token');
        });
    }

    private function headers(): array
    {
        return [
            'App-Token'     => $this->appToken,
            'Session-Token' => $this->getSessionToken(),
            'Content-Type'  => 'application/json',
        ];
    }

    // -------------------------------------------------------------------------
    // TICKETS (Para TicketController y GlpiController)
    // -------------------------------------------------------------------------
    public function createTicket(array $data): array
    {
        $payload = [
            'input' => [
                'name'      => $data['name'],
                'content'   => $data['content'],
                'urgency'   => $data['urgency'] ?? 3,
                'type'      => 1,  // 1 = Incident, 2 = Request
                'status'    => 1,  // 1 = New
                '_users_id_requester' => $this->findOrCreateUser($data['requester']),
            ],
        ];

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/Ticket", $payload);

        if ($response->failed()) {
            Log::error('GLPI: Error al crear ticket', ['response' => $response->body()]);
            throw new \RuntimeException('No se pudo crear el ticket de soporte.');
        }

        return $response->json();
    }

    public function getTicket(int $id): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/Ticket/{$id}");

        if ($response->notFound()) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Ticket #{$id} no encontrado.");
        }

        return $response->json();
    }

    // Usado por TicketController (Público)
    public function getTicketsByUser(string $email): array
    {
        $userId = $this->findOrCreateUser($email);
        return $this->getUserTickets($userId);
    }

    // Usado por GlpiController (Admin) y por getTicketsByUser
    public function getUserTickets(int $glpiUserId): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/search/Ticket", [
                'criteria[0][field]'      => 4, // 4 = ID del Solicitante (Requester)
                'criteria[0][searchtype]' => 'equals',
                'criteria[0][value]'      => $glpiUserId,
                'forcedisplay[0]'         => 2,  // ID
                'forcedisplay[1]'         => 1,  // Título
                'forcedisplay[2]'         => 21, // Contenido
                'forcedisplay[3]'         => 12, // Estado
                'forcedisplay[4]'         => 10, // Urgencia
                'forcedisplay[5]'         => 15, // Fecha
                'range'                   => '0-49',
                'sort'                    => 15,
                'order'                   => 'DESC',
            ]);

        if ($response->failed()) {
            return [];
        }

        $searchData = $response->json()['data'] ?? [];

        return array_map(function ($ticket) {
            return [
                'id'       => $ticket['2'] ?? null,
                'name'     => $ticket['1'] ?? null,
                'content'  => $ticket['21'] ?? null,
                'status'   => $ticket['12'] ?? null, 
                'urgency'  => $ticket['10'] ?? null,
                'date'     => $ticket['15'] ?? null,
            ];
        }, $searchData);
    }

    // -------------------------------------------------------------------------
    // USUARIOS
    // -------------------------------------------------------------------------
    private function findOrCreateUser(string $email): int
    {
        $search = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/User", [
                'searchText[name]' => $email, // Usamos 'name' corregido
                'range'            => '0-1',
            ]);

        if ($search->ok() && !empty($search->json())) {
            return $search->json()[0]['id'];
        }

        $create = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/User", [
                'input' => ['name' => $email],
            ]);

        return $create->json('id');
    }

    // Usado por GlpiController (Búsqueda de administradores)
    public function searchUser(string $query): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/User", [
                'searchText[name]' => $query,
                'range'            => '0-20',
            ]);

        return $response->json() ?? [];
    }

    // -------------------------------------------------------------------------
    // ACTIVOS / ASSETS (Para GlpiController)
    // -------------------------------------------------------------------------
    
    // Usado por GlpiController::listAssets
    public function getItems(string $type, array $params = []): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/{$type}", $params);

        return $response->json() ?? [];
    }

    // Usado por GlpiController::showAsset
    public function getItem(string $type, int $id): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/{$type}/{$id}");

        if ($response->notFound()) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Activo {$type} #{$id} no encontrado.");
        }

        return $response->json();
    }
}