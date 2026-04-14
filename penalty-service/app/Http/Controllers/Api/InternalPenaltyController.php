<?php

namespace App\Http\Controllers\Api;

// =============================================================================
// InternalPenaltyController — Endpoints consumidos exclusivamente por
// el loan-service vía X-Internal-Secret. No pasan por JWT.
//
// Endpoints:
//   POST /internal/penalties/generate → Crear multa desde loan-service
//   GET  /internal/penalties/check/{userId} → Verificar multas activas
// =============================================================================

use App\Http\Controllers\Controller;
use App\Services\PenaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalPenaltyController extends Controller
{
    public function __construct(private readonly PenaltyService $penaltyService) {}

    // ── POST /api/v1/internal/penalties/generate ──────────────────────────────
    public function generate(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'loan_id'          => ['required', 'string'],
            'user_id'          => ['required', 'string'],
            'user_name'        => ['required', 'string'],
            'user_email'       => ['required', 'email'],
            'book_title'       => ['required', 'string'],
            'copy_code'        => ['nullable', 'string'],

            // Datos para cálculo
            'type'             => ['nullable', 'in:overdue,damage,loss,combined'],
            'days_overdue'     => ['nullable', 'integer', 'min:0'],
            'condition_out'    => ['nullable', 'in:new,good,worn,damaged'],
            'condition_in'     => ['nullable', 'in:new,good,worn,damaged,lost'],
            'has_damage'       => ['nullable', 'boolean'],
            'acquisition_cost' => ['nullable', 'numeric', 'min:0'],

            'generated_by'      => ['nullable', 'string'],
            'generated_by_name' => ['nullable', 'string'],
            'notes'             => ['nullable', 'string'],
        ]);

        try {
            $penalty = $this->penaltyService->generate($data);

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'           => $penalty->id,
                    'type'         => $penalty->type,
                    'total_amount' => $penalty->total_amount,
                    'status'       => $penalty->status,
                    'breakdown'    => [
                        'days_overdue'   => $penalty->days_overdue,
                        'overdue_amount' => $penalty->overdue_amount,
                        'damage_amount'  => $penalty->damage_amount,
                        'loss_amount'    => $penalty->loss_amount,
                    ],
                ],
            ], 201);

        } catch (\InvalidArgumentException $e) {
            // Monto calculado = 0 → no se genera multa
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => 'ZERO_AMOUNT',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar la multa.',
            ], 500);
        }
    }

    // ── GET /api/v1/internal/penalties/check/{userId} ─────────────────────────
    public function check(Request $request, string $userId): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json(
            $this->penaltyService->getUserPendingSummary($userId)
        );
    }

    private function isAuthorized(Request $request): bool
    {
        return $request->header('X-Internal-Secret') === config('app.internal_secret');
    }
}