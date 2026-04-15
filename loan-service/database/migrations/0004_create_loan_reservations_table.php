<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// loan_reservations — Cola de espera cuando todos los ejemplares están prestados.
// Al devolver un ejemplar, el sistema notifica al primer lector en la cola.
// =============================================================================

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('loan_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('book_id');        // FK lógica a inventory.books
            $table->string('book_title');   // Desnormalizado

            $table->uuid('user_id');
            $table->string('user_name');
            $table->string('user_email');

            $table->enum('status', [
                'waiting',    // En cola de espera
                'notified',   // Notificado — tiene 48h para retirar
                'fulfilled',  // Se convirtió en préstamo
                'expired',    // No retiró a tiempo
                'cancelled',  // Canceló la reserva
            ])->default('waiting');

            $table->unsignedSmallInteger('queue_position');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('expires_at')->nullable();  // 48h tras notificación

            $table->timestamps();

            $table->index(['book_id', 'status', 'queue_position']);
            $table->index(['user_id', 'status']);

            // Un lector no puede reservar el mismo libro dos veces
            $table->unique(['book_id', 'user_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('loan_reservations'); }
};