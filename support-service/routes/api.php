<?php

use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

// =============================================================================
// Todas las rutas del support-service requieren JWT válido.
// El servicio no tiene rutas públicas — GLPI solo es accesible para
// usuarios autenticados en el ecosistema.
// =============================================================================

Route::middleware('jwt')->group(function () {

    // ── Tickets de soporte ────────────────────────────────────────────────────

    // Cualquier usuario autenticado puede ver y crear sus propios tickets
    Route::prefix('tickets')->group(function () {
        Route::get('/',              [TicketController::class, 'index']);
        Route::post('/',             [TicketController::class, 'store']);
        Route::get('/{id}',          [TicketController::class, 'show']);
        Route::get('/{id}/followups',[TicketController::class, 'followUps']);
        Route::post('/{id}/followups',[TicketController::class, 'addFollowUp']);
    });

    // ── Activos (solo admin y bibliotecario) ──────────────────────────────────
    Route::middleware('jwt:admin,bibliotecario')->group(function () {
        Route::get('assets', [TicketController::class, 'searchAssets']);
    });
});

// Health-check — verifica conectividad con GLPI
Route::get('/health', function () {
    $glpiOk = false;
    try {
        // Ping ligero a GLPI sin crear sesión completa
        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->withHeaders(['App-Token' => config('glpi.app_token')])
            ->get(config('glpi.url') . '/apirest.php/getMyProfiles');
        $glpiOk = $response->ok();
    } catch (\Throwable) {}

    return response()->json([
        'service' => 'support-service',
        'status'  => $glpiOk ? 'ok' : 'degraded',
        'glpi'    => $glpiOk ? 'reachable' : 'unreachable',
        'time'    => now()->toIso8601String(),
    ], $glpiOk ? 200 : 503);
});