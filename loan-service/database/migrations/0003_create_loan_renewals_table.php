<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Historial completo de renovaciones por préstamo.
// Una renovación registra el cambio de due_at anterior → nuevo.

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('loan_renewals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('loan_id');
            $table->foreign('loan_id')
                  ->references('id')->on('loans')
                  ->onDelete('cascade');

            $table->unsignedTinyInteger('renewal_number');  // 1, 2, 3…
            $table->timestamp('previous_due_at');
            $table->timestamp('new_due_at');

            // Quién autorizó (lector puede pedir, bibliotecario aprueba)
            $table->uuid('requested_by');
            $table->string('requested_by_name');
            $table->enum('requested_by_role', ['lector', 'bibliotecario', 'admin']);

            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('loan_id');
        });
    }

    public function down(): void { Schema::dropIfExists('loan_renewals'); }
};