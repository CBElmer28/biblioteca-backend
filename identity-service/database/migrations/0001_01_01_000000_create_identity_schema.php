<?php

// =============================================================================
// Migración 0 — Crear el esquema lógico "identity" en Supabase/PostgreSQL
// DEBE ejecutarse primero antes de cualquier otra migración.
// Comando: php artisan migrate --database=pgsql_direct
// =============================================================================

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Crear esquema si no existe; no falla si ya fue creado
        DB::statement('CREATE SCHEMA IF NOT EXISTS identity');

        // Establecer search_path para que todas las tablas caigan aquí
        DB::statement('SET search_path TO identity');
    }

    public function down(): void
    {
        // ⚠ DROP CASCADE elimina todo en el esquema — solo en desarrollo
        DB::statement('DROP SCHEMA IF EXISTS identity CASCADE');
    }
};