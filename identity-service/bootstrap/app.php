<?php

// =============================================================================
// bootstrap/app.php — Laravel 11: registrar middleware y alias de rutas
// =============================================================================

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',              // Prefijo global para todas las rutas
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Registrar JwtMiddleware con alias 'jwt'
        // Uso en rutas: Route::middleware('jwt') o Route::middleware('jwt:admin')
        $middleware->alias([
            'jwt' => \App\Http\Middleware\JwtMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Respuesta JSON uniforme para ModelNotFoundException (findOrFail)
        $exceptions->render(function (
            \Illuminate\Database\Eloquent\ModelNotFoundException $e,
            \Illuminate\Http\Request $request
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Recurso no encontrado.',
            ], 404);
        });
    })->create();