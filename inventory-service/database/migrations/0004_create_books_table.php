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
        Schema::create('books', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // ── Identificadores bibliográficos ────────────────────────────────
            $table->string('isbn_13', 13)->unique()->nullable();
            $table->string('isbn_10', 10)->unique()->nullable();
            $table->unsignedBigInteger('glpi_id')->nullable()->unique()->comment('ID del activo en GLPI');

            // ── Metadatos del título ──────────────────────────────────────────
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('slug')->unique();
            $table->text('synopsis')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('publisher', 200)->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('edition', 50)->nullable();
            $table->string('language', 5)->default('es');
            $table->unsignedSmallInteger('pages')->nullable();

            // ── Clasificación bibliotecaria ───────────────────────────────────
            $table->string('dewey_code', 20)->nullable();
            $table->string('location_hint', 100)->nullable();

            // ── Tipo: físico vs digital ───────────────────────────────────────
            // is_digital = true  → e-book con licencia ilimitada, sin copias, sin multas
            // is_digital = false → libro físico con ejemplares, stock y control de daños
            $table->boolean('is_digital')->default(false);

            // Requerido cuando is_digital = true (URL de streaming del archivo)
            // NULL obligatorio cuando is_digital = false
            $table->string('digital_file_url')->nullable();

            // ── Contadores desnormalizados (solo aplican a libros físicos) ────
            // Actualizados por el servicio cada vez que un copy cambia de estado.
            // Evitan N+1 queries al consultar disponibilidad desde el loan-service.
            $table->unsignedSmallInteger('total_copies')->default(0);
            $table->unsignedSmallInteger('available_copies')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_digital');
            $table->index('is_active');
            $table->index(['is_digital', 'available_copies']); // Filtro compuesto frecuente
        });

        // ── Columna generada tsvector para Full-Text Search en PostgreSQL ─────
        // Peso A → título (mayor relevancia)
        // Peso B → subtítulo
        // Peso C → sinopsis
        DB::statement("
            ALTER TABLE inventory.books
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('spanish', coalesce(title, '')), 'A') ||
                setweight(to_tsvector('spanish', coalesce(subtitle, '')), 'B') ||
                setweight(to_tsvector('spanish', coalesce(synopsis, '')), 'C')
            ) STORED
        ");

        // Índice GIN sobre el tsvector — único índice que soporta @@ operator
        DB::statement(
            'CREATE INDEX books_search_gin_idx ON inventory.books USING GIN (search_vector)'
        );

        // ── Pivotes ───────────────────────────────────────────────────────────
        Schema::create('book_author', function (Blueprint $table) {
            $table->uuid('book_id');
            $table->uuid('author_id');
            $table->enum('role', ['primary', 'coauthor', 'translator', 'editor'])
                  ->default('primary');
            $table->primary(['book_id', 'author_id']);
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->foreign('author_id')->references('id')->on('authors')->onDelete('cascade');
        });

        Schema::create('book_category', function (Blueprint $table) {
            $table->uuid('book_id');
            $table->uuid('category_id');
            $table->primary(['book_id', 'category_id']);
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_category');
        Schema::dropIfExists('book_author');
        Schema::dropIfExists('books');
    }
};