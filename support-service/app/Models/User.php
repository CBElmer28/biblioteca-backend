<?php

namespace App\Models;

// =============================================================================
// Model: User (Versión Stateless para Support Service)
// 
// Este modelo NO se conecta a la base de datos.
// Actúa como un contenedor de memoria (DTO) que recibe los claims del token JWT
// emitido por el identity-service.
// =============================================================================

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    // Respetamos la estructura UUID del identity-service
    protected $keyType    = 'string';
    public    $incrementing = false;

    // Permitimos que el JWT inyecte todos los atributos dinámicamente
    protected $guarded = [];

    // -------------------------------------------------------------------------
    // Compatibilidad con JwtMiddleware
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        // Leemos el 'status' inyectado desde los claims del JWT
        // Si por alguna razón no viene, asumimos que está activo para no bloquear
        return ($this->status ?? 'active') === 'active';
    }

    // -------------------------------------------------------------------------
    // Compatibilidad con Permisos (Reemplazo ligero de Spatie)
    // -------------------------------------------------------------------------

    /**
     * Verifica si el usuario tiene al menos uno de los roles solicitados.
     * Lee directamente el array 'roles' que viene dentro del payload del JWT.
     */
    public function hasAnyRole(array|string $roles): bool
    {
        // Normalizamos los roles solicitados a un array
        $rolesToCheck = is_array($roles) ? $roles : func_get_args();
        
        // Obtenemos los roles del usuario desde los atributos (inyectados por JWT)
        $userRoles = $this->roles ?? [];
        
        if (!is_array($userRoles)) {
            $userRoles = [$userRoles];
        }

        // Si hay intersección, significa que tiene el rol
        return count(array_intersect($rolesToCheck, $userRoles)) > 0;
    }
}