<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\ReturnLoanRequest;
use App\Http\Requests\Loan\StoreLoanRequest;
use App\Models\Loan;
use App\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class LoanController extends Controller
{
    public function __construct(private readonly LoanService $loanService) {}

    // ── GET /api/v1/loans — Admin/bibliotecario ve todos; lector solo los suyos
    public function index(Request $request): JsonResponse
    {
        $user  = JWTAuth::user();
        $query = Loan::with('renewals')->latest('loaned_at');

        // Lectores solo ven sus propios préstamos
        if ($user->hasRole('lector')) {
            $query->byUser($user->id);
        } else {
            // Filtros disponibles para admin/bibliotecario
            $query
                ->when($request->user_id,  fn($q) => $q->where('user_id', $request->user_id))
                ->when($request->status,   fn($q) => $q->where('status', $request->status))
                ->when($request->book_id,  fn($q) => $q->where('book_id', $request->book_id))
                ->when($request->overdue,  fn($q) => $q->overdue())
                ->when($request->due_today, fn($q) =>
                    $q->whereDate('due_at', today())->active()
                );
        }

        return response()->json([
            'success' => true,
            'data'    => $query->paginate($request->integer('per_page', 15)),
        ]);
    }

    // ── GET /api/v1/loans/{id}
    public function show(string $id): JsonResponse
    {
        $loan = Loan::with('renewals')->findOrFail($id);
        $user = JWTAuth::user();

        // El lector solo puede ver sus propios préstamos
        if ($user->hasRole('lector') && $loan->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        return response()->json(['success' => true, 'data' => $loan]);
    }

    // ── POST /api/v1/loans — Crear préstamo (bibliotecario/admin)
    public function store(StoreLoanRequest $request): JsonResponse
    {
        $librarian = JWTAuth::user();

        try {
            $loan = $this->loanService->create(
                bookData:      $request->input('book'),
                userData:      $request->input('user'),
                librarianData: ['id' => $librarian->id, 'name' => $librarian->name],
            );

            return response()->json([
                'success' => true,
                'message' => 'Préstamo registrado correctamente.',
                'data'    => $loan,
            ], 201);

        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error interno al crear préstamo.'], 500);
        }
    }

    // ── POST /api/v1/loans/{id}/return — Registrar devolución
    public function return(ReturnLoanRequest $request, string $id): JsonResponse
    {
        $loan      = Loan::findOrFail($id);
        $librarian = JWTAuth::user();

        try {
            $loan = $this->loanService->return(
                loan:          $loan,
                conditionIn:   $request->condition_in,
                librarianId:   $librarian->id,
                librarianName: $librarian->name,
                returnNotes:   $request->return_notes,
            );

            return response()->json([
                'success'     => true,
                'message'     => 'Devolución registrada.',
                'had_damage'  => $loan->hasDamage(),
                'penalty_generated' => !is_null($loan->penalty_id),
                'data'        => $loan,
            ]);

        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── POST /api/v1/loans/{id}/renew — Renovar préstamo
    public function renew(Request $request, string $id): JsonResponse
    {
        $loan = Loan::findOrFail($id);
        $user = JWTAuth::user();

        // Lector solo puede renovar sus propios préstamos
        if ($user->hasRole('lector') && $loan->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Acceso denegado.'], 403);
        }

        try {
            $loan = $this->loanService->renew(
                loan:              $loan,
                requestedById:     $user->id,
                requestedByName:   $user->name,
                requestedByRole:   $user->getRoleNames()->first() ?? 'lector',
                notes:             $request->notes,
            );

            return response()->json([
                'success'          => true,
                'message'          => 'Préstamo renovado.',
                'new_due_at'       => $loan->due_at->toIso8601String(),
                'renewals_left'    => $loan->remaining_renewals,
                'data'             => $loan,
            ]);

        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── POST /api/v1/loans/{id}/lost — Marcar como perdido
    public function markLost(Request $request, string $id): JsonResponse
    {
        $loan      = Loan::findOrFail($id);
        $librarian = JWTAuth::user();

        try {
            $loan = $this->loanService->markAsLost(
                loan:          $loan,
                librarianId:   $librarian->id,
                librarianName: $librarian->name,
                notes:         $request->notes,
            );

            return response()->json([
                'success' => true,
                'message' => 'Préstamo marcado como perdido. Multa generada.',
                'data'    => $loan,
            ]);

        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    // ── GET /api/v1/loans/stats — Estadísticas para el dashboard
    public function stats(): JsonResponse
    {
        $stats = [
            'active'   => Loan::active()->count(),
            'overdue'  => Loan::overdue()->count(),
            'due_today' => Loan::dueWithinDays(0)->count(),
            'due_tomorrow' => Loan::dueWithinDays(1)
                ->where('due_at', '>=', today()->addDay())
                ->count(),
            'returned_this_month' => Loan::where('status', 'returned')
                ->whereMonth('returned_at', now()->month)
                ->count(),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }
}