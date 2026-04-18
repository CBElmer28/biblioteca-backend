<?php

use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CopyController;
use Illuminate\Support\Facades\Route;

// =============================================================================
// Rutas PÚBLICAS — Catálogo visible sin autenticación
// (cualquier visitante puede explorar el inventario)
// =============================================================================
Route::get('books',                    [BookController::class, 'index']);
Route::get('books/available',          [BookController::class, 'available']);
Route::get('books/{slug}',             [BookController::class, 'show']);
Route::get('books/{bookId}/copies',    [CopyController::class, 'index']);
Route::get('copies/find/{copyCode}',   [CopyController::class, 'showByCode']);
Route::get('authors',                  [AuthorController::class, 'index']);
Route::get('authors/{slug}',           [AuthorController::class, 'show']);
Route::get('categories',               [CategoryController::class, 'index']);

// =============================================================================
// Rutas PROTEGIDAS — Solo bibliotecarios y admin gestionan el inventario
// Middleware 'jwt:admin,bibliotecario' → JwtMiddleware con verificación de roles
// =============================================================================
Route::middleware('jwt:admin,bibliotecario')->group(function () {

    // ── Gestión de libros ──────────────────────────────────────────────────
    Route::post('books',           [BookController::class, 'store']);
    Route::put('books/{id}',       [BookController::class, 'update']);
    Route::delete('books/{id}',    [BookController::class, 'destroy']);

    // ── Gestión de ejemplares físicos ──────────────────────────────────────
    Route::post('books/{bookId}/copies',       [CopyController::class, 'store']);
    Route::put('copies/{id}',                  [CopyController::class, 'update']);
    Route::patch('copies/{id}/condition',      [CopyController::class, 'updateCondition']);
    Route::delete('copies/{id}',               [CopyController::class, 'destroy']);

    // ── Gestión de autores y categorías ────────────────────────────────────
    Route::post('authors',         [AuthorController::class, 'store']);
    Route::put('authors/{id}',     [AuthorController::class, 'update']);
    Route::post('categories',      [CategoryController::class, 'store']);
    Route::put('categories/{id}',  [CategoryController::class, 'update']);

    Route::post('books/{id}/sync',  [BookController::class, 'sync']);
    Route::post('copies/{id}/sync', [CopyController::class, 'sync']);

    Route::patch('internal/books/{id}/glpi-id', [\App\Http\Controllers\Api\BookController::class, 'updateGlpiId']);
    Route::patch('internal/copies/{id}/glpi-id', [\App\Http\Controllers\Api\CopyController::class, 'updateGlpiId']);
});

// =============================================================================
// Endpoint INTERNO — Solo accesible por loan-service (X-Internal-Secret)
// No pasa por JWT — comunicación service-to-service
// =============================================================================
Route::post(
    'internal/copies/{id}/transition',
    [CopyController::class, 'internalTransition']
);

// Health-check
Route::get('/health', fn() => response()->json([
    'service' => 'inventory-service',
    'status'  => 'ok',
    'time'    => now()->toIso8601String(),
]));