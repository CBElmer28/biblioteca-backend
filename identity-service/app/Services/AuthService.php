<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * AuthService — Lógica de negocio de autenticación.
 *
 * El controlador solo delega aquí. Esta capa no conoce
 * nada de HTTP (sin Request ni Response) para mantenerse
 * testeable de forma independiente.
 */
class AuthService
{
    /**
     * Registra un nuevo usuario y devuelve el token.
     *
     * @throws ValidationException si el email ya existe
     */
    public function register(array $data): array
    {
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'], // El cast 'hashed' lo encripta
            'role'     => $data['role'] ?? 'member',
        ]);

        $token = $this->issueToken($user, 'register');

        return [
            'user'  => $this->formatUser($user),
            'token' => $token,
        ];
    }

    /**
     * Autentica credenciales y devuelve un token nuevo.
     * Revoca todos los tokens anteriores del usuario (sesión única por defecto).
     *
     * @throws AuthenticationException si las credenciales son inválidas o el usuario está inactivo
     */
    public function login(string $email, string $password): array
    {
        $user = User::active()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('Credenciales inválidas.');
        }

        // Revocar tokens previos — una sesión activa por usuario.
        // Si el proyecto requiere multi-device en el futuro,
        // comentar esta línea y filtrar por nombre de dispositivo.
        $user->tokens()->delete();

        $token = $this->issueToken($user, 'login');

        return [
            'user'  => $this->formatUser($user),
            'token' => $token,
        ];
    }

    /**
     * Revoca el token actual del usuario (logout).
     */
    public function logout(User $user): void
    {
        // currentAccessToken() es el token usado en esta request
        $user->currentAccessToken()->delete();
    }

    /**
     * Valida un token Bearer y devuelve el payload del usuario.
     * Este método es llamado exclusivamente por TokenValidationController,
     * que a su vez es invocado por Nginx vía auth_request.
     *
     * @throws AuthenticationException si el token no existe, expiró o el usuario está inactivo
     */
    public function validateToken(string $rawToken): array
    {
        // Sanctum hashea el token con SHA-256 para buscarlo en BD
        $accessToken = PersonalAccessToken::findToken($rawToken);

        if (! $accessToken) {
            throw new AuthenticationException('Token inválido o expirado.');
        }

        $user = $accessToken->tokenable;

        if (! $user || ! $user->is_active) {
            throw new AuthenticationException('Usuario inactivo.');
        }

        // Verificar expiración si el token tiene fecha límite
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            $accessToken->delete();
            throw new AuthenticationException('Token expirado.');
        }

        // Actualizar last_used_at sin afectar updated_at del modelo
        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $this->formatUser($user);
    }

    // ── Métodos privados ──────────────────────────────────────

    /**
     * Emite un token de Sanctum con vida útil configurable.
     * La expiración se define en config/sanctum.php (expiration en minutos).
     */
    private function issueToken(User $user, string $tokenName): string
    {
        $expiresAt = config('sanctum.expiration')
            ? now()->addMinutes(config('sanctum.expiration'))
            : null;

        return $user->createToken(
            $tokenName,
            ['*'],          // Habilidades — '*' = acceso completo
            $expiresAt
        )->plainTextToken; // Solo se devuelve en texto plano una vez
    }

    /**
     * Formato consistente del usuario para todas las respuestas.
     * Nunca exponer password, remember_token ni relaciones internas.
     */
    private function formatUser(User $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'role'         => $user->role,
            'glpi_user_id' => $user->glpi_user_id,
        ];
    }
}