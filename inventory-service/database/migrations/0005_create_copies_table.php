<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// =============================================================================
// copies — Ejemplar físico individual. Corazón del módulo físico.
// REGLA DURA: Solo puede existir si el libro padre tiene is_digital = false.
// Esta restricción se aplica a nivel de FormRequest Y de modelo (boot).
// =============================================================================

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('copies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('book_id');
            $table->foreign('book_id')
                  ->references('id')->on('books')
                  ->onDelete('restrict'); // No eliminar libro con copias

            // Código de identificación física (etiqueta / código de barras / RFID)
            // Formato: BIB-YYYY-NNNNNN  → ej: BIB-2024-000042
            $table->string('copy_code', 30)->unique();

            // ── Condición física del ejemplar ─────────────────────────────────
            $table->enum('condition', ['new', 'good', 'worn', 'damaged', 'lost'])
                  ->default('new');

            // ── Máquina de estados del ejemplar ──────────────────────────────
            // available  → Puede prestarse
            // loaned     → En posesión de un lector
            // reserved   → Reservado, pendiente de retiro
            // in_repair  → En restauración / encuadernación
            // withdrawn  → Dado de baja (estado terminal)
            $table->enum('status', ['available', 'loaned', 'reserved', 'in_repair', 'withdrawn'])
                  ->default('available');

            // Ubicación física: Sala-Estante-Nivel-Posición (ej: "A-03-2-15")
            $table->string('location', 30)->nullable();

            // false → Solo consulta en sala, no se presta a domicilio
            $table->boolean('is_loanable')->default(true);

            // Contador de veces que fue prestado (métricas de uso)
            $table->unsignedSmallInteger('loan_count')->default(0);

            $table->date('acquired_at')->nullable();
            $table->decimal('acquisition_cost', 8, 2)->nullable(); // Para multas por pérdida

            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('book_id');
            $table->index('status');
            $table->index('condition');
            $table->index('copy_code');
        });

        // Secuencia correlativa para copy_code
        DB::statement("CREATE SEQUENCE IF NOT EXISTS inventory.copy_code_seq START 1");

        // Restricción a nivel de base de datos: solo libros físicos pueden tener copias
        // CHECK a través de una función para poder referenciar la tabla padre
        DB::statement("
            ALTER TABLE inventory.copies
            ADD CONSTRAINT copies_only_for_physical_books
            CHECK (
                (SELECT is_digital FROM inventory.books WHERE id = book_id) = false
            )
        ");
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS inventory.copy_code_seq');
        Schema::dropIfExists('copies');
    }
};