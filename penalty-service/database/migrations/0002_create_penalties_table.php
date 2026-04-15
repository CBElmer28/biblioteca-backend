<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// penalties — Una multa por préstamo. Puede acumular varios conceptos
// (retraso + daño) en un solo registro para simplificar el cobro al lector.
//
// Ciclo de vida:
//   pending → paid      (pagado en un solo pago)
//   pending → partial   (pagos parciales en curso)
//   partial → paid      (último pago completa el total)
//   pending → waived    (condonada por admin)
//   partial → waived    (condonado el saldo pendiente)
// =============================================================================

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('penalties', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ── Referencia al préstamo causante ───────────────────────────────
            $table->uuid('loan_id');              // FK lógica a loans.loans
            $table->uuid('user_id');              // FK lógica a identity.users
            $table->string('user_name', 150);     // Desnormalizado
            $table->string('user_email', 255);    // Para notificaciones

            // ── Contexto del libro/ejemplar (snapshot) ────────────────────────
            $table->string('book_title');
            $table->string('copy_code', 30)->nullable();

            // ── Tipo de multa ─────────────────────────────────────────────────
            // overdue  → solo retraso
            // damage   → solo daño físico
            // loss     → pérdida del ejemplar
            // combined → retraso + daño en la misma devolución
            $table->enum('type', ['overdue', 'damage', 'loss', 'combined']);

            // ── Desglose de conceptos ─────────────────────────────────────────
            $table->integer('days_overdue')->default(0);
            $table->decimal('overdue_amount', 8, 2)->default(0.00);  // días × tarifa diaria
            $table->decimal('damage_amount',  8, 2)->default(0.00);  // tarifa fija por nivel de daño
            $table->decimal('loss_amount',    8, 2)->default(0.00);  // costo de reposición

            // Monto total = overdue_amount + damage_amount + loss_amount
            $table->decimal('total_amount', 10, 2);

            // ── Control de pagos ──────────────────────────────────────────────
            $table->decimal('amount_paid', 10, 2)->default(0.00);
            $table->decimal('amount_pending', 10, 2)           // total - paid
                  ->storedAs('total_amount - amount_paid');

            // ── Estado ────────────────────────────────────────────────────────
            $table->enum('status', ['pending', 'partial', 'paid', 'waived'])
                  ->default('pending');

            // ── Condonación (solo admin) ──────────────────────────────────────
            $table->uuid('waived_by')->nullable();
            $table->string('waived_by_name')->nullable();
            $table->text('waive_reason')->nullable();
            $table->timestamp('waived_at')->nullable();

            // ── Quién generó la multa ─────────────────────────────────────────
            $table->uuid('generated_by')->nullable();       // UUID bibliotecario/sistema
            $table->string('generated_by_name')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Índices ───────────────────────────────────────────────────────
            $table->index('user_id');
            $table->index('loan_id');
            $table->index('status');
            $table->index(['user_id', 'status']);  // Consulta más frecuente del loan-service
            $table->index('created_at');

            // Restricción: un préstamo genera máximo una multa
            $table->unique('loan_id');
        });
    }

    public function down(): void { Schema::dropIfExists('penalties'); }
};