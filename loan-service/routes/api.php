<?php

use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

// ── Rutas PROTEGIDAS — Todos los endpoints requieren JWT ──────────────────────
Route::middleware('jwt')->group(function () {

    // ── Préstamos ──────────────────────────────────────────────────────────────
    Route::prefix('loans')->group(function () {

        // Lectura: lector ve los suyos; admin/bibliotecario ve todos
        Route::get('/',            [LoanController::class, 'index']);
        Route::get('/stats',       [LoanController::class, 'stats']);
        Route::get('/{id}',        [LoanController::class, 'show']);

        // Renovar: lector puede pedir sobre sus propios préstamos
        Route::post('/{id}/renew', [LoanController::class, 'renew']);

        // Solo bibliotecario/admin: crear, devolver, marcar perdido
        Route::middleware('jwt:admin,bibliotecario')->group(function () {
            Route::post('/',              [LoanController::class, 'store']);
            Route::post('/{id}/return',   [LoanController::class, 'return']);
            Route::post('/{id}/lost',     [LoanController::class, 'markLost']);
        });
    });

    // ── Reservas ───────────────────────────────────────────────────────────────
    Route::prefix('reservations')->group(function () {
        Route::get('/',         [ReservationController::class, 'index']);
        Route::post('/',        [ReservationController::class, 'store']);
        Route::delete('/{id}',  [ReservationController::class, 'cancel']);
    });
});

// Health-check — incluye estado del scheduler
Route::get('/health', fn() => response()->json([
    'service'        => 'loan-service',
    'status'         => 'ok',
    'time'           => now()->toIso8601String(),
    'scheduler'      => 'active',
    'active_loans'   => \App\Models\Loan::active()->count(),
    'overdue_loans'  => \App\Models\Loan::overdue()->count(),
]));