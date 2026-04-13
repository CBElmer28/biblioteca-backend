<?php

// =============================================================================
// routes/api.php — Rutas del identity-service
// Prefijo base: /api/v1 (configurado en bootstrap/app.php)
// =============================================================================

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Rutas PÚBLICAS — Sin autenticación
// ---------------------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

// ---------------------------------------------------------------------------
// Rutas PROTEGIDAS — Requieren JWT válido
// Middleware 'jwt' = App\Http\Middleware\JwtMiddleware
// ---------------------------------------------------------------------------
Route::middleware('jwt')->group(function () {

    // Auth: perfil propio, refresh y logout
    Route::prefix('auth')->group(function () {
        Route::get('/me',      [AuthController::class, 'me']);
        Route::post('/refresh',[AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Tickets de soporte (todos los usuarios autenticados)
    Route::prefix('tickets')->group(function () {
        Route::get('/',     [TicketController::class, 'index']);
        Route::post('/',    [TicketController::class, 'store']);
        Route::get('/{id}', [TicketController::class, 'show']);
    });

    // -------------------------------------------------------------------
    // Rutas ADMIN — Solo para roles admin o support
    // Uso: Route::middleware('jwt:admin') o 'jwt:admin,support'
    // -------------------------------------------------------------------
    Route::middleware('jwt:admin')->group(function () {

        // CRUD de usuarios
        Route::apiResource('users', UserController::class)->except(['store']);
        Route::post('users/{id}/roles', [UserController::class, 'assignRole']);

        // Gestión de roles y permisos
        Route::get('roles',                       [RoleController::class, 'index']);
        Route::post('roles',                      [RoleController::class, 'store']);
        Route::put('roles/{id}/permissions',      [RoleController::class, 'syncPermissions']);
        Route::get('permissions',                 [RoleController::class, 'permissions']);
    });
});

// Health-check del servicio (usado por Nginx gateway)
Route::get('/health', fn() => response()->json([
    'service' => 'identity-service',
    'status'  => 'ok',
    'time'    => now()->toIso8601String(),
]));