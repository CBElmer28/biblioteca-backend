<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Auth\GenericUser; // Permite crear un objeto usuario "falso" en memoria

class JwtMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        try {
            // 1. Extrae y valida el token (Firma y Expiración) SIN ir a la base de datos
            $token = JWTAuth::parseToken();
            $token->checkOrFail(); // Lanza las excepciones abajo si algo falla

            // 2. Extraer los datos (claims) que vienen dentro del token
            $payload = $token->getPayload();

            // 3. Crear un "Usuario Virtual" en memoria con los datos del token
            // Nota: El identity-service DEBE incluir 'role' y 'status' al crear el token
            $tokenRoles = $payload->get('roles');
            if (!is_array($tokenRoles)) {
                // Si por algún motivo viene como string, lo convertimos a array, o ponemos 'user' por defecto
                $tokenRoles = $tokenRoles ? [$tokenRoles] : ['user']; 
            }

            // 4. Crear el "Usuario Virtual"
            $user = new GenericUser([
                'id'     => $payload->get('sub'),
                'roles'  => $tokenRoles, // Guardamos el array completo
                'status' => $payload->get('status') ?? 'active',
                'name'   => $payload->get('name') ?? 'Usuario',
            ]);

            // 5. Verificar estado
            if ($user->status !== 'active' && $user->status !== 'pending_verification') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cuenta inactiva o suspendida.',
                ], 403);
            }

            // 6. Verificar Roles usando intersección de Arrays
            if (!empty($roles)) {
                // array_intersect compara qué elementos coinciden entre ambos arrays.
                // Si la coincidencia está vacía, significa que no tiene el rol necesario.
                $hasPermission = !empty(array_intersect($user->roles, $roles));

                if (!$hasPermission) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tienes permisos suficientes. Roles requeridos: ' . implode(', ', $roles),
                    ], 403);
                }
            }

            // 7. Inyectar usuario virtual en el request
            $request->attributes->set('auth_user', $user);

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