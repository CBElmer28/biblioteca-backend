<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// copy_condition_logs — Auditoría inmutable de cambios de estado/condición.
// Responde: ¿quién cambió qué, cuándo y en qué contexto?
// No tiene updated_at ni softDeletes: los logs nunca se modifican.
// =============================================================================

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('copy_condition_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('copy_id');
            $table->foreign('copy_id')
                  ->references('id')->on('copies')
                  ->onDelete('cascade');

            // Snapshot del estado anterior y nuevo
            $table->string('from_condition', 20)->nullable();
            $table->string('to_condition', 20)->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            // Quién realizó el cambio (UUID del bibliotecario del identity-service)
            $table->uuid('changed_by')->nullable();
            $table->string('changed_by_name', 150)->nullable(); // Desnormalizado

            // Contexto que originó el cambio
            // loan_return | loan_create | manual_inspection | acquisition | withdrawal
            $table->string('context', 30)->default('manual_inspection');

            // Si el cambio ocurrió durante un préstamo, referencia cruzada
            $table->uuid('loan_id')->nullable(); // ID del loan-service (sin FK real)

            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent(); // Solo insert, no update

            $table->index('copy_id');
            $table->index('changed_by');
            $table->index('context');
            $table->index('created_at');
        });
    }

    public function down(): void { Schema::dropIfExists('copy_condition_logs'); }
};