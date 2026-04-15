<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// penalty_payments — Historial de pagos de una multa.
// Permite pagos parciales: un lector puede pagar en cuotas.
// Los pagos son inmutables — nunca se eliminan ni modifican.
// =============================================================================

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('penalty_payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('penalty_id');
            $table->foreign('penalty_id')
                  ->references('id')->on('penalties')
                  ->onDelete('restrict');  // No eliminar multa con pagos

            $table->decimal('amount', 10, 2);

            // Medio de pago registrado por el bibliotecario
            $table->enum('payment_method', ['cash', 'transfer', 'card', 'other'])
                  ->default('cash');

            $table->string('reference_number')->nullable();  // Número de operación/voucher
            $table->text('notes')->nullable();

            // Quién registró el pago (bibliotecario en ventanilla)
            $table->uuid('received_by');
            $table->string('received_by_name', 150);

            // Snapshot del saldo antes y después del pago (para auditoría)
            $table->decimal('balance_before', 10, 2);
            $table->decimal('balance_after',  10, 2);

            $table->timestamp('paid_at')->useCurrent();

            $table->index('penalty_id');
            $table->index('paid_at');
        });
    }

    public function down(): void { Schema::dropIfExists('penalty_payments'); }
};