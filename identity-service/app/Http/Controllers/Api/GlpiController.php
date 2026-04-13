<?php

namespace App\Http\Controllers\Api;

use App\Services\GlpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GlpiController — Proxy hacia la API de GLPI.
 *
 * Solo usuarios con rol 'admin' o 'librarian' pueden consultar GLPI.
 * El middleware 'auth:sanctum' garantiza que hay un usuario autenticado;
 * la verificación de rol se hace aquí para mantener las rutas simples.
 */
class GlpiController extends Controller
{
    public function __construct(private GlpiService $glpiService) {}

    // ── GET /glpi/assets ──────────────────────────────────────
    // Lista activos de un tipo. Ejemplo: ?type=Computer&range=0-49

    public function listAssets(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'type'  => 'required|string|alpha',   // Computer, Monitor…
            'range' => 'sometimes|string|regex:/^\d+-\d+$/',
        ]);

        $items = $this->glpiService->getItems(
            $validated['type'],
            array_filter(['range' => $validated['range'] ?? null])
        );

        return response()->json(['success' => true, 'data' => $items]);
    }

    // ── GET /glpi/assets/{type}/{id} ──────────────────────────

    public function showAsset(Request $request, string $type, int $id): JsonResponse
    {
        $this->authorizeStaff($request);

        $item = $this->glpiService->getItem($type, $id);

        return response()->json(['success' => true, 'data' => $item]);
    }

    // ── GET /glpi/users/search ────────────────────────────────
    // Solo admins pueden buscar usuarios en GLPI

    public function searchUsers(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        $validated = $request->validate(['q' => 'required|string|min:2']);

        $users = $this->glpiService->searchUser($validated['q']);

        return response()->json(['success' => true, 'data' => $users]);
    }

    // ── GET /glpi/tickets/{glpiUserId} ────────────────────────

    public function userTickets(Request $request, int $glpiUserId): JsonResponse
    {
        $this->authorizeStaff($request);

        $tickets = $this->glpiService->getUserTickets($glpiUserId);

        return response()->json(['success' => true, 'data' => $tickets]);
    }

    // ── Helper ────────────────────────────────────────────────

    private function authorizeStaff(Request $request): void
    {
        if (! in_array($request->user()->role, ['admin', 'librarian'])) {
            abort(403, 'Solo personal autorizado puede acceder a GLPI.');
        }
    }
}