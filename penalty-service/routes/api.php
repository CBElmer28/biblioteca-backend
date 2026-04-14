<?php

use App\Http\Controllers\Api\InternalPenaltyController;
use App\Http\Controllers\Api\PenaltyController;
use Illuminate\Support\Facades\Route;

// ── Rutas INTERNAS — Solo loan-service (X-Internal-Secret) ───────────────────
Route::prefix('internal/penalties')->group(function () {
    Route::post('generate',           [InternalPenaltyController::class, 'generate']);
    Route::get('check/{userId}',      [InternalPenaltyController::class, 'check']);
});

// ── Rutas PROTEGIDAS — Requieren JWT ─────────────────────────────────────────
Route::middleware('jwt')->group(function () {

    Route::prefix('penalties')->group(function () {

        // Estadísticas — solo admin/bibliotecario
        Route::middleware('jwt:admin,bibliotecario')->group(function () {
            Route::get('/stats',                   [PenaltyController::class, 'stats']);
            Route::get('/user/{userId}/summary',   [PenaltyController::class, 'userSummary']);
        });

        // Lectura: lector ve las suyas; admin/bibliotecario ve todas
        Route::get('/',             [PenaltyController::class, 'index']);
        Route::get('/{id}',         [PenaltyController::class, 'show']);
        Route::get('/{id}/payments',[PenaltyController::class, 'payments']);

        // Pagos y condonaciones — solo bibliotecario/admin en ventanilla
        Route::middleware('jwt:admin,bibliotecario')->group(function () {
            Route::post('/{id}/pay',   [PenaltyController::class, 'pay']);
        });

        // Condonación — exclusiva para admin
        Route::middleware('jwt:admin')->group(function () {
            Route::post('/{id}/waive', [PenaltyController::class, 'waive']);
        });
    });
});

// Health-check
Route::get('/health', function () {
    $stats = [
        'pending' => \App\Models\Penalty::pending()->count(),
        'total_pending_amount' => \App\Models\Penalty::pending()->sum('amount_pending'),
    ];

    return response()->json([
        'service' => 'penalty-service',
        'status'  => 'ok',
        'time'    => now()->toIso8601String(),
        'stats'   => $stats,
    ]);
});