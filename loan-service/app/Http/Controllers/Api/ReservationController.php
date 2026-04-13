<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class ReservationController extends Controller
{
    // ── POST /api/v1/reservations — El lector reserva un libro no disponible
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'book_id'    => ['required', 'uuid'],
            'book_title' => ['required', 'string'],
        ]);

        $user = JWTAuth::user();

        // Verificar que no tenga ya una reserva activa del mismo libro
        $existing = LoanReservation::where('book_id', $data['book_id'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['waiting', 'notified'])
            ->first();

        if ($existing) {
            return response()->json([
                'success'  => false,
                'message'  => 'Ya tienes una reserva activa para este libro.',
                'position' => $existing->queue_position,
            ], 409);
        }

        // Calcular posición en la cola
        $nextPosition = LoanReservation::where('book_id', $data['book_id'])
            ->whereIn('status', ['waiting', 'notified'])
            ->max('queue_position') + 1;

        $reservation = LoanReservation::create([
            'book_id'        => $data['book_id'],
            'book_title'     => $data['book_title'],
            'user_id'        => $user->id,
            'user_name'      => $user->name,
            'user_email'     => $user->email,
            'status'         => 'waiting',
            'queue_position' => $nextPosition,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => "Reserva registrada. Posición en cola: {$nextPosition}.",
            'data'     => $reservation,
        ], 201);
    }

    // ── DELETE /api/v1/reservations/{id} — Cancelar reserva
    public function cancel(string $id): JsonResponse
    {
        $reservation = LoanReservation::findOrFail($id);
        $user        = JWTAuth::user();

        if ($reservation->user_id !== $user->id && !$user->hasAnyRole(['admin', 'bibliotecario'])) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        if (!in_array($reservation->status, ['waiting', 'notified'])) {
            return response()->json([
                'success' => false,
                'message' => 'Esta reserva ya no puede cancelarse.',
            ], 422);
        }

        $reservation->update(['status' => 'cancelled']);

        // Reordenar la cola del libro
        LoanReservation::where('book_id', $reservation->book_id)
            ->where('status', 'waiting')
            ->where('queue_position', '>', $reservation->queue_position)
            ->decrement('queue_position');

        return response()->json(['success' => true, 'message' => 'Reserva cancelada.']);
    }

    // ── GET /api/v1/reservations — Ver reservas (propias si lector)
    public function index(Request $request): JsonResponse
    {
        $user  = JWTAuth::user();
        $query = LoanReservation::latest();

        if ($user->hasRole('lector')) {
            $query->where('user_id', $user->id);
        } else {
            $query->when($request->status,  fn($q) => $q->where('status', $request->status))
                  ->when($request->book_id, fn($q) => $q->where('book_id', $request->book_id));
        }

        return response()->json([
            'success' => true,
            'data'    => $query->paginate(15),
        ]);
    }
}