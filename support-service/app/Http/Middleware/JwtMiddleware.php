<?php

namespace App\Http\Middleware;

// =============================================================================
// JwtMiddleware — Validar token JWT en cada request a rutas protegidas.
// Los otros microservicios también usarán este mismo middleware para
// verificar tokens emitidos por el identity-service.
// =============================================================================

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        try {
            // Extrae y valida el token del header Authorization: Bearer <token>
            $user = JWTAuth::parseToken()->authenticate();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado.',
                ], 401);
            }

            // Verificar que la cuenta esté activa
            if (!$user->isActive() && $user->status !== 'pending_verification') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cuenta inactiva o suspendida.',
                ], 403);
            }

            // Verificar roles si se especificaron en la ruta (ej: 'jwt:admin,support')
            if (!empty($roles) && !$user->hasAnyRole($roles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a este recurso.',
                ], 403);
            }

        } catch (TokenExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token expirado. Usa /auth/refresh para renovarlo.',
                'code'    => 'TOKEN_EXPIRED',
            ], 401);

        } catch (TokenInvalidException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido.',
                'code'    => 'TOKEN_INVALID',
            ], 401);

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token no proporcionado.',
                'code'    => 'TOKEN_ABSENT',
            ], 401);
        }

        return $next($request);
    }
}