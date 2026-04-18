<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::create('copies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->unsignedBigInteger('glpi_id')->nullable()->unique()->comment('ID del activo Copy en GLPI');

            $table->uuid('book_id');
            $table->foreign('book_id')
                  ->references('id')->on('books')
                  ->onDelete('restrict'); 

            $table->string('copy_code', 30)->unique();

            $table->enum('condition', ['new', 'good', 'worn', 'damaged', 'lost'])
                  ->default('new');

            $table->enum('status', ['available', 'loaned', 'reserved', 'in_repair', 'withdrawn'])
                  ->default('available');

            $table->string('location', 30)->nullable();
            $table->boolean('is_loanable')->default(true);
            $table->unsignedSmallInteger('loan_count')->default(0);

            $table->date('acquired_at')->nullable();
            $table->decimal('acquisition_cost', 8, 2)->nullable(); 

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

        // 1. Crear la función PL/pgSQL que verifica si el libro es digital
        DB::statement("
            CREATE OR REPLACE FUNCTION inventory.check_copy_is_physical()
            RETURNS TRIGGER AS $$
            DECLARE
                v_is_digital BOOLEAN;
            BEGIN
                -- Buscar el flag is_digital del libro padre
                SELECT is_digital INTO v_is_digital FROM inventory.books WHERE id = NEW.book_id;
                
                -- Si es digital, abortar la transacción con una excepción
                IF v_is_digital = true THEN
                    RAISE EXCEPTION 'Violación de integridad: No se pueden crear copias físicas para libros digitales (E-books).';
                END IF;
                
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        // 2. Asociar la función a un Trigger en la tabla copies
        DB::statement("
            CREATE TRIGGER enforce_physical_copies_only
            BEFORE INSERT OR UPDATE ON inventory.copies
            FOR EACH ROW
            EXECUTE FUNCTION inventory.check_copy_is_physical();
        ");
    }

    public function down(): void
    {
        // Borrar el trigger y la función antes de la tabla
        DB::statement('DROP TRIGGER IF EXISTS enforce_physical_copies_only ON inventory.copies');
        DB::statement('DROP FUNCTION IF EXISTS inventory.check_copy_is_physical()');
        DB::statement('DROP SEQUENCE IF EXISTS inventory.copy_code_seq');
        
        Schema::dropIfExists('copies');
    }
};