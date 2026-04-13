<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Usar la conexión directa (sin PgBouncer) para migraciones
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Datos personales
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('phone', 20)->nullable();
            $table->string('avatar_url')->nullable();

            // Estado de la cuenta
            $table->enum('status', ['active', 'inactive', 'suspended', 'pending_verification'])
                  ->default('pending_verification');

            // Metadatos de auditoría
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes(); // Para GDPR: borrado lógico, no físico

            // Índices para búsquedas frecuentes
            $table->index('status');
            $table->index('email');
        });

        // Tabla de tokens de reset de contraseña
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};