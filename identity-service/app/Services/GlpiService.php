<?php

namespace App\Services;

// =============================================================================
// GlpiService — Cliente HTTP para la API REST de GLPI
// Documentación GLPI API: https://github.com/glpi-project/glpi/blob/main/apirest.md
// =============================================================================

use Illuminate\Http\Client\Response;
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
    // Iniciar sesión en GLPI y obtener session_token (cacheado 50 min)
    // -------------------------------------------------------------------------
    private function getSessionToken(): string
    {
        return Cache::remember('glpi_session_token', now()->addMinutes(50), function () {
            $response = Http::withHeaders([
                'App-Token'        => $this->appToken,
                'Authorization'    => "user_token {$this->userToken}",
                'Content-Type'     => 'application/json',
            ])->get("{$this->baseUrl}/initSession");

            if ($response->failed()) {
                Log::error('GLPI: No se pudo iniciar sesión', ['status' => $response->status()]);
                throw new \RuntimeException('No se pudo conectar con el sistema de soporte.');
            }

            return $response->json('session_token');
        });
    }

    // -------------------------------------------------------------------------
    // Headers base para todas las peticiones autenticadas
    // -------------------------------------------------------------------------
    private function headers(): array
    {
        return [
            'App-Token'      => $this->appToken,
            'Session-Token'  => $this->getSessionToken(),
            'Content-Type'   => 'application/json',
        ];
    }

    // -------------------------------------------------------------------------
    // Crear un ticket en GLPI
    // -------------------------------------------------------------------------
    public function createTicket(array $data): array
    {
        $payload = [
            'input' => [
                'name'      => $data['name'],
                'content'   => $data['content'],
                'urgency'   => $data['urgency'],
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

    // -------------------------------------------------------------------------
    // Obtener un ticket por ID
    // -------------------------------------------------------------------------
    public function getTicket(int $id): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/Ticket/{$id}");

        if ($response->notFound()) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Ticket #{$id} no encontrado.");
        }

        return $response->json();
    }

    // -------------------------------------------------------------------------
    // Obtener tickets asociados a un email de usuario
    // -------------------------------------------------------------------------
    public function getTicketsByUser(string $email): array
    {
        $userId = $this->findOrCreateUser($email);

        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/Ticket", [
                'searchText[_users_id_requester]' => $userId,
                'range'                           => '0-49',  // Máximo 50 tickets
                'sort'                            => 'date_mod',
                'order'                           => 'DESC',
            ]);

        return $response->json() ?? [];
    }

    // -------------------------------------------------------------------------
    // Buscar usuario en GLPI por email; si no existe, lo crea
    // -------------------------------------------------------------------------
    private function findOrCreateUser(string $email): int
    {
        // Buscar usuario existente
        $search = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/User", [
                'searchText[email]' => $email,
                'range'             => '0-1',
            ]);

        if ($search->ok() && !empty($search->json())) {
            return $search->json()[0]['id'];
        }

        // Crear usuario si no existe
        $create = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/User", [
                'input' => [
                    'name'  => $email,
                    'email' => $email,
                ],
            ]);

        return $create->json('id');
    }
}