<?php

use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\CopyController;
use Illuminate\Support\Facades\Route;

// Rutas Públicas (Lectores explorando el catálogo)
Route::get('/books', [BookController::class, 'index']);
Route::get('/books/{id}', [BookController::class, 'show']);

// Rutas Protegidas (Requieren Token JWT válido enviado desde el API Gateway)
Route::middleware('auth.jwt')->group(function () {
    
    // Solo personal autorizado puede modificar el inventario
    // Nota: El middleware 'role' asume que lo copiaste del identity-service o lo verificas vía claims del JWT
    Route::middleware('role:admin,bibliotecario')->group(function () {
        Route::post('/books', [BookController::class, 'store']);
        Route::post('/copies', [CopyController::class, 'store']);
        
        // Aquí irían también los endpoints de Author y Category
    });
});