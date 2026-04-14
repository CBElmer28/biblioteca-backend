<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Penalty\RecordPaymentRequest;
use App\Http\Requests\Penalty\WaivePenaltyRequest;
use App\Models\Penalty;
use App\Services\PenaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class PenaltyController extends Controller
{
    public function __construct(private readonly PenaltyService $penaltyService) {}

    // ── GET /api/v1/penalties ─────────────────────────────────────────────────
    // Admin/bibliotecario: todos | Lector: solo los suyos
    public function index(Request $request): JsonResponse
    {
        $user  = JWTAuth::user();
        $query = Penalty::with('payments')->latest();

        if ($user->hasRole('lector')) {
            $query->byUser($user->id);
        } else {
            $query
                ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
                ->when($request->status,  fn($q) => $q->where('status', $request->status))
                ->when($request->type,    fn($q) => $q->where('type', $request->type))
                ->when($request->date_from, fn($q) =>
                    $q->whereDate('created_at', '>=', $request->date_from)
                )
                ->when($request->date_to, fn($q) =>
                    $q->whereDate('created_at', '<=', $request->date_to)
                );
        }

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->integer('per_page', 20)),
        ]);
    }

    // ── GET /api/v1/penalties/{id} ────────────────────────────────────────────
    public function show(string $id): JsonResponse
    {
        $penalty = Penalty::with('payments')->findOrFail($id);
        $user    = JWTAuth::user();

        if ($user->hasRole('lector') && $penalty->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge($penalty->toArray(), [
                'remaining_amount'   => $penalty->remaining_amount,
                'payment_progress'   => $penalty->payment_progress,
                'type_label'         => config("penalties.types.{$penalty->type}"),
            ]),
        ]);
    }

    // ── POST /api/v1/penalties/{id}/pay ───────────────────────────────────────
    public function pay(RecordPaymentRequest $request, string $id): JsonResponse
    {
        $penalty    = Penalty::findOrFail($id);
        $librarian  = JWTAuth::user();

        try {
            $payment = $this->penaltyService->pay(
                penalty:         $penalty,
                amount:          (float) $request->amount,
                paymentMethod:   $request->payment_method,
                receivedById:    $librarian->id,
                receivedByName:  $librarian->name,
                referenceNumber: $request->reference_number,
                notes:           $request->notes,
            );

            $penalty->refresh();

            return response()->json([
                'success'          => true,
                'message'          => $penalty->isPaid()
                    ? 'Multa saldada completamente.'
                    : 'Pago parcial registrado.',
                'payment'          => $payment,
                'penalty_status'   => $penalty->status,
                'remaining_amount' => $penalty->remaining_amount,
            ], 201);

        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── POST /api/v1/penalties/{id}/waive — Solo admin ────────────────────────
    public function waive(WaivePenaltyRequest $request, string $id): JsonResponse
    {
        $penalty = Penalty::findOrFail($id);
        $admin   = JWTAuth::user();

        try {
            $penalty = $this->penaltyService->waive(
                penalty:      $penalty,
                waivedById:   $admin->id,
                waivedByName: $admin->name,
                reason:       $request->reason,
            );

            return response()->json([
                'success' => true,
                'message' => 'Multa condonada correctamente.',
                'data'    => $penalty,
            ]);

        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── GET /api/v1/penalties/stats ───────────────────────────────────────────
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->penaltyService->getStats(),
        ]);
    }

    // ── GET /api/v1/penalties/user/{userId}/summary ───────────────────────────
    // Usada por el bibliotecario antes de registrar un nuevo préstamo
    public function userSummary(string $userId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->penaltyService->getUserPendingSummary($userId),
        ]);
    }

    // ── GET /api/v1/penalties/{id}/payments — Historial de pagos ─────────────
    public function payments(string $id): JsonResponse
    {
        $penalty = Penalty::findOrFail($id);
        $user    = JWTAuth::user();

        if ($user->hasRole('lector') && $penalty->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $penalty->payments,
        ]);
    }
}