<?php

namespace App\Http\Controllers\Api;

// =============================================================================
// AuthController — Registro, Login, Refresh, Logout, Perfil propio
// Toda la autenticación es stateless via JWT (sin sesiones ni cookies).
// =============================================================================

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    // -------------------------------------------------------------------------
    // POST /api/v1/auth/register
    // -------------------------------------------------------------------------
    public function register(RegisterRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => $request->password, // Auto-hashed via cast
                'phone'    => $request->phone,
                'status'   => 'pending_verification',
            ]);

            // Crear perfil vacío asociado al usuario
            UserProfile::create(['user_id' => $user->id]);

            // Asignar rol "customer" por defecto al registrarse
            $user->assignRole('customer');

            // Generar token JWT inmediatamente tras el registro
            $token = JWTAuth::fromUser($user);

            DB::commit();

            // Disparar evento para que notification-service envíe email de bienvenida
            // event(new UserRegistered($user)); // ← Fase 5: notification

            return $this->respondWithToken($token, $user, 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el usuario.',
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/login
    // -------------------------------------------------------------------------
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        $user = User::where('email', $request->email)->first();

        // Verificar si la cuenta está bloqueada por intentos fallidos
        if ($user && $user->isLocked()) {
            return response()->json([
                'success' => false,
                'message' => 'Cuenta bloqueada temporalmente. Intenta en 30 minutos.',
            ], 423);
        }

        // Verificar si la cuenta está activa (o pendiente de verificación)
        if ($user && $user->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Tu cuenta ha sido suspendida. Contacta a soporte.',
            ], 403);
        }

        try {
            if (!$token = JWTAuth::attempt($credentials)) {
                // Registrar intento fallido si el usuario existe
                $user?->recordFailedLogin();

                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incorrectas.',
                ], 401);
            }
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo generar el token.',
            ], 500);
        }

        $user = JWTAuth::user();
        $user->resetLoginAttempts();  // Login exitoso → reset intentos

        return $this->respondWithToken($token, $user);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/refresh — Refrescar token antes de que expire
    // -------------------------------------------------------------------------
    public function refresh(): JsonResponse
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            return $this->respondWithToken($newToken, JWTAuth::user());
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido o expirado. Inicia sesión nuevamente.',
            ], 401);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/auth/logout — Invalidar token en la blacklist de JWT
    // -------------------------------------------------------------------------
    public function logout(): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json(['success' => true, 'message' => 'Sesión cerrada.']);
        } catch (JWTException $e) {
            return response()->json(['success' => false, 'message' => 'Error al cerrar sesión.'], 500);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/auth/me — Perfil del usuario autenticado
    // -------------------------------------------------------------------------
    public function me(): JsonResponse
    {
        $user = JWTAuth::user()->load('profile');

        return response()->json([
            'success' => true,
            'data'    => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'phone'      => $user->phone,
                'status'     => $user->status,
                'roles'      => $user->getRoleNames(),
                'permissions'=> $user->getAllPermissions()->pluck('name'),
                'profile'    => $user->profile,
                'last_login' => $user->last_login_at?->toIso8601String(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Helper: Estructura uniforme de respuesta con token JWT
    // -------------------------------------------------------------------------
    private function respondWithToken(string $token, User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'success'      => true,
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => config('jwt.ttl') * 60,  // Segundos
            'user' => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'status' => $user->status,
                'roles'  => $user->getRoleNames(),
            ],
        ], $status);
    }
}