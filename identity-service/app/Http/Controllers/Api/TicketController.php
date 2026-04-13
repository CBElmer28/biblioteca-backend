<?php

namespace App\Http\Controllers;

// =============================================================================
// TicketController — Tickets de soporte vía integración GLPI
// Los usuarios crean tickets; el soporte los gestiona en GLPI directamente.
// =============================================================================

use App\Http\Controllers\Controller;
use App\Services\GlpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class TicketController extends Controller
{
    public function __construct(private readonly GlpiService $glpi) {}

    // GET /api/v1/tickets — Tickets del usuario autenticado
    public function index(): JsonResponse
    {
        $user   = JWTAuth::user();
        $result = $this->glpi->getTicketsByUser($user->email);

        return response()->json(['success' => true, 'data' => $result]);
    }

    // POST /api/v1/tickets — Crear nuevo ticket de soporte
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'    => ['required', 'string', 'max:255'],
            'content'  => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'in:order,payment,account,other'],
            'urgency'  => ['nullable', 'integer', 'between:1,5'], // 1=muy bajo, 5=muy alto
        ]);

        $user = JWTAuth::user();

        $ticket = $this->glpi->createTicket([
            'name'        => $data['title'],
            'content'     => $data['content'],
            'urgency'     => $data['urgency'] ?? 3,
            'requester'   => $user->email,
            'category'    => $data['category'] ?? 'other',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ticket creado exitosamente.',
            'data'    => $ticket,
        ], 201);
    }

    // GET /api/v1/tickets/{id}
    public function show(int $id): JsonResponse
    {
        $ticket = $this->glpi->getTicket($id);
        return response()->json(['success' => true, 'data' => $ticket]);
    }
}