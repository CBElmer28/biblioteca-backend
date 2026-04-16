<?php

namespace App\Http\Controllers\Api;

// =============================================================================
// TicketController — API REST para que el frontend gestione tickets de soporte.
//
// El controlador solo conoce el GlpiService (ACL).
// Nunca habla directamente con GLPI — esa responsabilidad es del GlpiService.
// =============================================================================

use App\Http\Controllers\Controller;
use App\Services\GlpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class TicketController extends Controller
{
    public function __construct(private readonly GlpiService $glpi) {}

    // ── GET /api/v1/tickets — Listar tickets del usuario autenticado ──────────
    public function index(Request $request): JsonResponse
    {
        $user   = JWTAuth::user();
        $limit  = $request->integer('limit', 50);

        try {
            $tickets = $this->glpi->getTicketsByUser($user->email, $limit);

            return response()->json([
                'success' => true,
                'data'    => $tickets,
                'total'   => count($tickets),
            ]);

        } catch (\Throwable $e) {
            return $this->glpiError($e, 'No se pudieron obtener los tickets.');
        }
    }

    // ── GET /api/v1/tickets/{id} — Detalle de un ticket ──────────────────────
    public function show(int $id): JsonResponse
    {
        try {
            $ticket   = $this->glpi->getTicket($id);
            $followUps = $this->glpi->getFollowUps($id);

            return response()->json([
                'success'    => true,
                'data'       => array_merge($ticket, ['follow_ups' => $followUps]),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => "Ticket #{$id} no encontrado.",
            ], 404);

        } catch (\Throwable $e) {
            return $this->glpiError($e, "No se pudo obtener el ticket #{$id}.");
        }
    }

    // ── POST /api/v1/tickets — Crear ticket desde el frontend ────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'    => ['required', 'string', 'min:5', 'max:255'],
            'content'  => ['required', 'string', 'min:10', 'max:5000'],
            'urgency'  => ['nullable', 'integer', 'between:1,5'],
            'category' => ['nullable', 'string', 'in:book_damage,loan_dispute,user_account,inventory,general'],
        ]);

        $user = JWTAuth::user();

        try {
            $result = $this->glpi->createTicket([
                'title'           => $data['title'],
                'content'         => $data['content'],
                'requester_email' => $user->email,
                'urgency'         => $data['urgency'] ?? config('glpi.urgency.normal'),
                'type'            => config('glpi.ticket_type.request'),
                'category_id'     => $this->resolveCategoryId($data['category'] ?? 'general'),
                'metadata'        => [
                    'user_id'   => $user->id,
                    'user_name' => $user->name,
                    'source'    => 'frontend',
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ticket creado exitosamente.',
                'data'    => $result,
            ], 201);

        } catch (\Throwable $e) {
            return $this->glpiError($e, 'No se pudo crear el ticket.');
        }
    }

    // ── POST /api/v1/tickets/{id}/followups — Agregar comentario ─────────────
    public function addFollowUp(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'content'    => ['required', 'string', 'min:5', 'max:3000'],
            'is_private' => ['nullable', 'boolean'],
        ]);

        // Solo admin/bibliotecario pueden agregar notas privadas
        $user      = JWTAuth::user();
        $isPrivate = $data['is_private'] ?? false;

        if ($isPrivate && !$user->hasAnyRole(['admin', 'bibliotecario'])) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para agregar notas privadas.',
            ], 403);
        }

        try {
            $result = $this->glpi->addFollowUp($id, $data['content'], $isPrivate);

            return response()->json([
                'success' => true,
                'message' => 'Comentario agregado.',
                'data'    => $result,
            ], 201);

        } catch (\Throwable $e) {
            return $this->glpiError($e, "No se pudo agregar el comentario al ticket #{$id}.");
        }
    }

    // ── GET /api/v1/tickets/{id}/followups — Historial de comentarios ─────────
    public function followUps(int $id): JsonResponse
    {
        try {
            $followUps = $this->glpi->getFollowUps($id);

            return response()->json([
                'success' => true,
                'data'    => $followUps,
                'total'   => count($followUps),
            ]);

        } catch (\Throwable $e) {
            return $this->glpiError($e, "No se pudieron obtener los comentarios del ticket #{$id}.");
        }
    }

    // ── GET /api/v1/assets — Buscar activos en GLPI ───────────────────────────
    public function searchAssets(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query'    => ['required', 'string', 'min:2', 'max:100'],
            'itemtype' => ['nullable', 'string', 'in:Computer,Monitor,Phone,Peripheral'],
        ]);

        try {
            $assets = $this->glpi->searchAssets(
                $data['query'],
                $data['itemtype'] ?? 'Computer'
            );

            return response()->json([
                'success' => true,
                'data'    => $assets,
                'total'   => count($assets),
            ]);

        } catch (\Throwable $e) {
            return $this->glpiError($e, 'No se pudieron buscar activos.');
        }
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    private function resolveCategoryId(?string $category): ?int
    {
        if (!$category) return null;

        return config("glpi.categories.{$category}");
    }

    private function glpiError(\Throwable $e, string $userMessage): JsonResponse
    {
        \Illuminate\Support\Facades\Log::error("TicketController: {$e->getMessage()}", [
            'exception' => get_class($e),
        ]);

        return response()->json([
            'success' => false,
            'message' => $userMessage,
            // En producción no exponer detalles del error de GLPI al frontend
        ], 503);
    }
}