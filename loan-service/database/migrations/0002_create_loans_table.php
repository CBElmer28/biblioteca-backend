<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// loans — Entidad central del sistema.
// Representa la relación (lector ↔ recurso) a lo largo del tiempo.
//
// Máquina de estados:
//   active → returned   (devolución normal)
//   active → overdue    (marcado por scheduler al superar due_at)
//   active → lost       (lector reporta pérdida o bibliotecario confirma)
//   overdue → returned  (devolución tardía — genera multa automática)
//   overdue → lost      (no devuelto tras período máximo)
//
// Un préstamo nunca regresa a "active" desde otro estado.
// =============================================================================

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ── Qué recurso se prestó ─────────────────────────────────────────
            // Para físicos: copy_id apunta al ejemplar exacto
            // Para digitales: copy_id es NULL (no hay ejemplar físico)
            $table->uuid('copy_id')->nullable();        // FK lógica a inventory.copies
            $table->uuid('book_id');                    // FK lógica a inventory.books
            $table->boolean('is_digital')->default(false);

            // Snapshot desnormalizado del libro al momento del préstamo
            // Evita cross-service query al mostrar historial años después
            $table->string('book_title');
            $table->string('book_isbn')->nullable();
            $table->string('book_author')->nullable();  // Autor principal
            $table->string('copy_code')->nullable();    // Solo para físicos

            // ── A quién se prestó ─────────────────────────────────────────────
            $table->uuid('user_id');                    // FK lógica a identity.users
            $table->string('user_name');                // Desnormalizado
            $table->string('user_email');               // Para notificaciones

            // ── Quién registró el préstamo (bibliotecario) ────────────────────
            $table->uuid('issued_by');
            $table->string('issued_by_name');

            // ── Fechas clave ──────────────────────────────────────────────────
            $table->timestamp('loaned_at');
            $table->timestamp('due_at');                // Vencimiento pactado
            $table->timestamp('returned_at')->nullable();

            // ── Máquina de estados ────────────────────────────────────────────
            $table->enum('status', [
                'active',    // Préstamo vigente
                'returned',  // Devuelto a tiempo
                'overdue',   // Vencido (marcado por scheduler)
                'lost',      // Ejemplar reportado como perdido
            ])->default('active');

            // ── Evaluación de condición (solo préstamos físicos) ──────────────
            // Condición al salir y al regresar — diferencia detecta daños
            $table->enum('condition_out', ['new', 'good', 'worn', 'damaged'])
                  ->nullable();
            $table->enum('condition_in',  ['new', 'good', 'worn', 'damaged', 'lost'])
                  ->nullable();
            $table->text('return_notes')->nullable();   // Observaciones al devolver

            // ── Control de renovaciones ───────────────────────────────────────
            $table->unsignedTinyInteger('renewals_count')->default(0);
            $table->unsignedTinyInteger('max_renewals');  // Copiado de config al crear

            // ── Referencia a multa generada (si aplica) ───────────────────────
            $table->uuid('penalty_id')->nullable();     // FK lógica a penalties.penalties

            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────────
            $table->index('user_id');
            $table->index('copy_id');
            $table->index('book_id');
            $table->index('status');
            $table->index('due_at');
            $table->index(['user_id', 'status']);       // Consulta más frecuente
            $table->index(['status', 'due_at']);        // Para el scheduler de vencimientos
        });
    }

    public function down(): void { Schema::dropIfExists('loans'); }
};