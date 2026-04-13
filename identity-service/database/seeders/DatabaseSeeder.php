<?php

namespace Database\Seeders;

// =============================================================================
// DatabaseSeeder — Identity Service
// Dominio: Sistema de Gestión Bibliotecaria Clásica
//
// Roles del sistema:
//   admin          → Control total del sistema
//   bibliotecario  → Gestión operativa diaria (préstamos, inventario, multas)
//   lector         → Usuario final que solicita y devuelve préstamos
//
// Convención de permisos: <recurso>.<acción>
// Ejecutar con: php artisan db:seed --database=pgsql_direct
// =============================================================================

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar caché de permisos antes de sembrar
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // =====================================================================
        // 1. PERMISOS — organizados por dominio de negocio
        // =====================================================================
        $permissions = [

            // ── Gestión de usuarios (identity-service) ────────────────────────
            'users.viewAny',        // Listar todos los usuarios
            'users.view',           // Ver perfil de un usuario
            'users.create',         // Registrar nuevo usuario
            'users.edit',           // Editar datos de usuario
            'users.delete',         // Dar de baja (soft delete)
            'users.suspender',      // Suspender cuenta de lector
            'users.roles.assign',   // Asignar/cambiar roles

            // ── Inventario — Libros (inventory-service) ───────────────────────
            'libros.viewAny',       // Listar catálogo completo
            'libros.view',          // Ver ficha de un libro
            'libros.create',        // Registrar nuevo título
            'libros.edit',          // Editar metadatos del libro
            'libros.delete',        // Dar de baja un título

            // ── Inventario — Ejemplares físicos (inventory-service) ───────────
            'ejemplares.viewAny',   // Listar ejemplares de un libro
            'ejemplares.view',      // Ver estado de un ejemplar
            'ejemplares.create',    // Registrar nuevo ejemplar físico
            'ejemplares.edit',      // Editar estado/ubicación de un ejemplar
            'ejemplares.delete',    // Dar de baja un ejemplar (pérdida/deterioro)
            'ejemplares.auditar',   // Ver historial completo de un ejemplar

            // ── Préstamos (loan-service) ──────────────────────────────────────
            'prestamos.viewAny',    // Ver todos los préstamos activos
            'prestamos.view',       // Ver detalle de un préstamo
            'prestamos.create',     // Registrar nuevo préstamo
            'prestamos.devolver',   // Registrar devolución
            'prestamos.renovar',    // Extender fecha de vencimiento
            'prestamos.cancelar',   // Cancelar préstamo activo
            'prestamos.historial',  // Ver historial completo de préstamos
            'prestamos.propios',    // Ver solo préstamos del propio lector

            // ── Multas (penalty-service) ──────────────────────────────────────
            'multas.viewAny',       // Ver todas las multas
            'multas.view',          // Ver detalle de una multa
            'multas.create',        // Generar multa manualmente
            'multas.editar',        // Ajustar monto o descripción
            'multas.condonar',      // Perdonar/anular una multa (solo admin)
            'multas.pagar',         // Registrar pago de multa
            'multas.propias',       // Ver solo multas del propio lector

            // ── Reportes y estadísticas ───────────────────────────────────────
            'reportes.prestamos',   // Reporte de préstamos por período
            'reportes.inventario',  // Reporte de estado del inventario
            'reportes.multas',      // Reporte financiero de multas
            'reportes.lectores',    // Actividad por lector
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate([
                'name'       => $perm,
                'guard_name' => 'api',
            ]);
        }

        $this->command->info('✅ ' . count($permissions) . ' permisos creados.');

        // =====================================================================
        // 2. ROLES y asignación de permisos
        // =====================================================================

        // ── ROL: admin ────────────────────────────────────────────────────────
        // Acceso total al sistema, incluyendo condonación de multas y reportes.
        $adminRole = Role::firstOrCreate([
            'name'       => 'admin',
            'guard_name' => 'api',
        ]);
        $adminRole->syncPermissions($permissions);  // Todo

        // ── ROL: bibliotecario ────────────────────────────────────────────────
        // Operaciones diarias: registrar préstamos, devoluciones, ejemplares.
        // NO puede condonar multas ni acceder a gestión de usuarios avanzada.
        $bibliotecarRole = Role::firstOrCreate([
            'name'       => 'bibliotecario',
            'guard_name' => 'api',
        ]);
        $bibliotecarRole->syncPermissions([
            // Usuarios (solo lectura y suspensión básica)
            'users.viewAny',
            'users.view',
            'users.suspender',

            // Inventario — control total
            'libros.viewAny',
            'libros.view',
            'libros.create',
            'libros.edit',
            'ejemplares.viewAny',
            'ejemplares.view',
            'ejemplares.create',
            'ejemplares.edit',
            'ejemplares.auditar',

            // Préstamos — control total operativo
            'prestamos.viewAny',
            'prestamos.view',
            'prestamos.create',
            'prestamos.devolver',
            'prestamos.renovar',
            'prestamos.cancelar',
            'prestamos.historial',

            // Multas — puede crear y registrar pagos, NO condonar
            'multas.viewAny',
            'multas.view',
            'multas.create',
            'multas.pagar',

            // Reportes operativos
            'reportes.prestamos',
            'reportes.inventario',
            'reportes.lectores',
        ]);

        // ── ROL: lector ───────────────────────────────────────────────────────
        // Usuario final: solo puede ver el catálogo y gestionar sus propios
        // préstamos y multas. No puede ver datos de otros lectores.
        $lectorRole = Role::firstOrCreate([
            'name'       => 'lector',
            'guard_name' => 'api',
        ]);
        $lectorRole->syncPermissions([
            // Catálogo — solo lectura pública
            'libros.viewAny',
            'libros.view',
            'ejemplares.viewAny',   // Ver disponibilidad de ejemplares

            // Sus propios préstamos
            'prestamos.propios',
            'prestamos.renovar',    // Puede solicitar renovación (sujeta a aprobación)

            // Sus propias multas
            'multas.propias',
            'multas.pagar',         // Puede registrar el pago de sus multas
        ]);

        $this->command->info('✅ 3 roles creados: admin, bibliotecario, lector.');

        // =====================================================================
        // 3. USUARIOS INICIALES DEL SISTEMA
        // =====================================================================

        // ── Administrador del sistema ─────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['email' => 'admin@biblioteca-clasica.pe'],
            [
                'name'   => 'Administrador del Sistema',
                'password' => 'Admin1234!',   // ← Cambiar en producción
                'status' => 'active',
            ]
        );
        $admin->assignRole('admin');

        // ── Bibliotecario de prueba ───────────────────────────────────────────
        $bibliotecario = User::firstOrCreate(
            ['email' => 'bibliotecario@biblioteca-clasica.pe'],
            [
                'name'   => 'Ana García (Bibliotecaria)',
                'password' => 'Biblio1234!',  // ← Cambiar en producción
                'status' => 'active',
            ]
        );
        $bibliotecario->assignRole('bibliotecario');

        // ── Lector de prueba ─────────────────────────────────────────────────
        $lector = User::firstOrCreate(
            ['email' => 'lector@biblioteca-clasica.pe'],
            [
                'name'   => 'Carlos Mendoza (Lector)',
                'password' => 'Lector1234!',  // ← Cambiar en producción
                'status' => 'active',
            ]
        );
        $lector->assignRole('lector');

        $this->command->info('✅ 3 usuarios iniciales creados.');
        $this->command->newLine();
        $this->command->table(
            ['Rol', 'Email', 'Contraseña (cambiar)'],
            [
                ['admin',         'admin@biblioteca-clasica.pe',        'Admin1234!'],
                ['bibliotecario', 'bibliotecario@biblioteca-clasica.pe', 'Biblio1234!'],
                ['lector',        'lector@biblioteca-clasica.pe',        'Lector1234!'],
            ]
        );
    }
}