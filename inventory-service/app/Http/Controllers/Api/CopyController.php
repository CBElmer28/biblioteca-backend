<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Copy\StoreCopyRequest;
use App\Http\Requests\Copy\UpdateCopyConditionRequest;
use App\Models\Book;
use App\Models\Copy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class CopyController extends Controller
{
    // ── GET /api/v1/books/{bookId}/copies ─────────────────────────────────────
    public function index(string $bookId): JsonResponse
    {
        $book   = Book::findOrFail($bookId);

        if ($book->is_digital) {
            return response()->json([
                'success' => false,
                'message' => 'Los e-books no tienen ejemplares físicos.',
            ], 422);
        }

        $copies = $book->copies()
            ->with('conditionLogs')
            ->orderBy('status')
            ->orderBy('location')
            ->get();

        return response()->json([
            'success' => true,
            'summary' => [
                'total'     => $copies->count(),
                'available' => $copies->where('status', 'available')->where('is_loanable', true)->count(),
                'loaned'    => $copies->where('status', 'loaned')->count(),
                'reserved'  => $copies->where('status', 'reserved')->count(),
                'in_repair' => $copies->where('status', 'in_repair')->count(),
                'withdrawn' => $copies->where('status', 'withdrawn')->count(),
            ],
            'data' => $copies,
        ]);
    }

    // ── GET /api/v1/copies/find/{copyCode} ────────────────────────────────────
    public function showByCode(string $copyCode): JsonResponse
    {
        $copy = Copy::where('copy_code', strtoupper($copyCode))
            ->with(['book:id,title,slug,isbn_13,is_digital', 'conditionLogs'])
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $copy]);
    }

    // ── POST /api/v1/books/{bookId}/copies ────────────────────────────────────
    public function store(StoreCopyRequest $request, string $bookId): JsonResponse
    {
        $book     = Book::findOrFail($bookId);
        $quantity = $request->integer('quantity', 1);

        DB::beginTransaction();
        try {
            $created = [];

            for ($i = 0; $i < $quantity; $i++) {
                // El modelo::booted() aplica la segunda capa de validación
                $copy = Copy::create([
                    'book_id'          => $book->id,
                    'copy_code'        => Copy::generateCopyCode(),
                    'condition'        => $request->condition,
                    'status'           => 'available',
                    'location'         => $request->location,
                    'is_loanable'      => $request->boolean('is_loanable', true),
                    'acquired_at'      => $request->acquired_at ?? today()->toDateString(),
                    'acquisition_cost' => $request->acquisition_cost,
                    'internal_notes'   => $request->internal_notes,
                ]);

                // Registro de adquisición en el log de auditoría
                $user = JWTAuth::user();
                \App\Models\CopyConditionLog::create([
                    'copy_id'          => $copy->id,
                    'from_condition'   => null,
                    'to_condition'     => $copy->condition,
                    'from_status'      => null,
                    'to_status'        => 'available',
                    'changed_by'       => $user->id,
                    'changed_by_name'  => $user->name,
                    'context'          => 'acquisition',
                    'notes'            => "Ejemplar ingresado al inventario.",
                ]);

                $created[] = $copy;
            }

            // recalculateCopyCounts ya es llamado por el observer del modelo,
            // pero lo forzamos aquí para garantizar consistencia en bulk inserts
            $book->recalculateCopyCounts();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$quantity} ejemplar(es) registrado(s) correctamente.",
                'data'    => $created,
            ], 201);

        } catch (\DomainException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al registrar ejemplar.'], 500);
        }
    }

    // ── PATCH /api/v1/copies/{id}/condition ───────────────────────────────────
    public function updateCondition(UpdateCopyConditionRequest $request, string $id): JsonResponse
    {
        $copy = Copy::findOrFail($id);
        $user = JWTAuth::user();

        try {
            $copy->transitionTo(
                newStatus:     $request->input('status', $copy->status),
                newCondition:  $request->input('condition'),
                changedBy:     $user->id,
                changedByName: $user->name,
                context:       'manual_inspection',
                notes:         $request->notes,
            );

            return response()->json([
                'success' => true,
                'message' => 'Estado del ejemplar actualizado.',
                'data'    => $copy->fresh('conditionLogs'),
            ]);

        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── PUT /api/v1/copies/{id} — Actualizar metadatos no críticos ────────────
    public function update(Request $request, string $id): JsonResponse
    {
        $copy = Copy::findOrFail($id);

        $data = $request->validate([
            'location'       => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_loanable'    => ['sometimes', 'boolean'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $copy->update($data);

        return response()->json(['success' => true, 'data' => $copy]);
    }

    // ── DELETE /api/v1/copies/{id} ────────────────────────────────────────────
    public function destroy(string $id): JsonResponse
    {
        $copy = Copy::findOrFail($id);

        if ($copy->status === 'loaned') {
            return response()->json([
                'success' => false,
                'message' => 'No se puede dar de baja un ejemplar actualmente en préstamo.',
            ], 409);
        }

        DB::beginTransaction();
        try {
            $user = JWTAuth::user();

            if ($copy->status !== 'withdrawn') {
                $copy->transitionTo(
                    newStatus:     'withdrawn',
                    changedBy:     $user->id,
                    changedByName: $user->name,
                    context:       'manual_inspection',
                    notes:         'Dado de baja definitivamente del inventario.'
                );
            }

            $copy->delete();
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Ejemplar dado de baja y eliminado.']);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── POST /api/v1/internal/copies/{id}/transition — Endpoint interno ───────
    // Llamado exclusivamente por loan-service al crear/cerrar préstamos.
    public function internalTransition(Request $request, string $id): JsonResponse
    {
        if ($request->header('X-Internal-Secret') !== config('app.internal_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'status'           => ['required', 'in:loaned,available,withdrawn'],
            'condition'        => ['nullable', 'in:new,good,worn,damaged,lost'],
            'loan_id'          => ['required', 'string'],
            'changed_by'       => ['nullable', 'string'],
            'changed_by_name'  => ['nullable', 'string'],
            'notes'            => ['nullable', 'string'],
        ]);

        $copy = Copy::findOrFail($id);

        try {
            $copy->transitionTo(
                newStatus:     $data['status'],
                newCondition:  $data['condition'] ?? null,
                changedBy:     $data['changed_by'] ?? null,
                changedByName: $data['changed_by_name'] ?? null,
                context:       $data['status'] === 'loaned' ? 'loan_create' : 'loan_return',
                loanId:        $data['loan_id'],
                notes:         $data['notes'] ?? null,
            );

            return response()->json([
                'success'          => true,
                'copy_code'        => $copy->copy_code,
                'new_status'       => $copy->status,
                'available_copies' => $copy->book->available_copies,
            ]);

        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}