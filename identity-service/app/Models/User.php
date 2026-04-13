<?php

namespace App\Models;

// =============================================================================
// Model: User
// Implementa JWTSubject para autenticación stateless y HasRoles de Spatie.
// Usa UUID como PK (más seguro y compatible con microservicios distribuidos).
// =============================================================================

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasRoles, SoftDeletes, HasUuids;

    // UUID como clave primaria (no auto-incremental)
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_url',
        'status',
        'last_login_ip',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'failed_login_attempts',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'last_login_at'         => 'datetime',
            'locked_until'          => 'datetime',
            'password'              => 'hashed',  // Auto-hash en Laravel 11
            'failed_login_attempts' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Implementación requerida por JWTSubject
    // -------------------------------------------------------------------------

    /** Retorna el identificador único para el payload del JWT */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /** Claims personalizados dentro del token JWT */
    public function getJWTCustomClaims(): array
    {
        return [
            'email'  => $this->email,
            'name'   => $this->name,
            'status' => $this->status,
            // Incluir roles en el token evita una consulta DB en cada request
            'roles'  => $this->getRoleNames(),
        ];
    }

    // -------------------------------------------------------------------------
    // Relaciones
    // -------------------------------------------------------------------------

    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    // -------------------------------------------------------------------------
    // Helpers de estado
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /** Incrementa intentos fallidos y bloquea tras 5 intentos */
    public function recordFailedLogin(): void
    {
        $this->increment('failed_login_attempts');

        if ($this->failed_login_attempts >= 5) {
            $this->update(['locked_until' => now()->addMinutes(30)]);
        }
    }

    public function resetLoginAttempts(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
            'last_login_ip'         => request()->ip(),
        ]);
    }
}