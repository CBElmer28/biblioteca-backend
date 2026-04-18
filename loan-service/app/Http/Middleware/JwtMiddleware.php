<?php

namespace App\Http\Middleware;

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
            // 1. Desempaqueta el token SIN ir a la base de datos
            $payload = JWTAuth::parseToken()->getPayload();

            // 2. Extraer datos directamente del JSON del token (Custom Claims)
            $userId = $payload->get('sub');
            $isActive = $payload->get('is_active'); // Debe ser inyectado por Identity
            $userStatus = $payload->get('status'); // Debe ser inyectado por Identity
            $userRoles = $payload->get('roles') ?? []; // Debe ser inyectado por Identity

            // Si llegamos aquí, la firma del token es válida y no ha expirado.
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token sin identificador de usuario válido.',
                ], 401);
            }

            // 3. Verificar estado de la cuenta basado en el token, no en la DB
            if (!$isActive && $userStatus !== 'pending_verification') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cuenta inactiva o suspendida.',
                ], 403);
            }

            // 4. Verificar roles interceptando los arrays
            if (!empty($roles)) {
                // Comprueba si hay alguna intersección entre los roles requeridos y los del usuario
                $hasRole = count(array_intersect($roles, $userRoles)) > 0;
                
                if (!$hasRole) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tienes permisos para acceder a este recurso.',
                    ], 403);
                }
            }

            // (Opcional) Inyectar los datos en el request para que los controladores puedan usarlos
            $request->attributes->add(['jwt_user_id' => $userId]);

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