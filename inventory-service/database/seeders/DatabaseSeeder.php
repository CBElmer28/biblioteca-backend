<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Copy;
use App\Models\CopyConditionLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Géneros literarios ────────────────────────────────────────────────
        $genres = [
            ['name' => 'Literatura Universal', 'children' => ['Novela', 'Cuento', 'Poesía', 'Teatro']],
            ['name' => 'Literatura Peruana',    'children' => ['Narrativa Peruana', 'Poesía Peruana']],
            ['name' => 'Ciencia Ficción',       'children' => ['Distopía', 'Space Opera', 'Cyberpunk']],
            ['name' => 'Tecnología',            'children' => ['Programación', 'DevOps', 'Inteligencia Artificial']],
            ['name' => 'Historia',              'children' => ['Historia Universal', 'Historia del Perú']],
        ];

        foreach ($genres as $i => $g) {
            $parent = Category::create(['name' => $g['name'], 'is_active' => true, 'sort_order' => $i]);
            foreach ($g['children'] as $j => $child) {
                Category::create(['name' => $child, 'parent_id' => $parent->id, 'is_active' => true, 'sort_order' => $j]);
            }
        }

        // ── Autores ───────────────────────────────────────────────────────────
        $vll    = Author::create(['name' => 'Mario Vargas Llosa',  'nationality' => 'PE', 'birth_date' => '1936-03-28']);
        $orwell = Author::create(['name' => 'George Orwell',       'nationality' => 'GB', 'birth_date' => '1903-06-25', 'death_date' => '1950-01-21']);
        $martin = Author::create(['name' => 'Robert C. Martin',    'nationality' => 'US', 'birth_date' => '1952-12-05']);

        $novela   = Category::where('name', 'Novela')->first();
        $distopia = Category::where('name', 'Distopía')->first();
        $prog     = Category::where('name', 'Programación')->first();

        // =====================================================================
        // LIBRO 1 — Físico con 2 copias
        // =====================================================================
        $physicalBook = Book::create([
            'title'            => 'La Ciudad y los Perros',
            'isbn_13'          => '9788420471839',
            'synopsis'         => 'Una novela que retrata la vida en el Colegio Militar Leoncio Prado de Lima, explorando temas de violencia, poder y traición.',
            'publisher'        => 'Alfaguara',
            'publication_year' => 1963,
            'language'         => 'es',
            'pages'            => 450,
            'dewey_code'       => '863.64',
            'location_hint'    => 'Sala A — Estante 03',
            'is_digital'       => false,
        ]);

        $physicalBook->authors()->attach($vll->id, ['role' => 'primary']);
        $physicalBook->categories()->sync([$novela->id]);

        // Copia 1 — Buen estado
        $copy1 = Copy::create([
            'book_id'          => $physicalBook->id,
            'copy_code'        => Copy::generateCopyCode(),
            'condition'        => 'good',
            'status'           => 'available',
            'location'         => 'A-03-1-01',
            'is_loanable'      => true,
            'acquired_at'      => '2023-01-15',
            'acquisition_cost' => 45.00,
        ]);

        // Copia 2 — Desgastada por uso
        $copy2 = Copy::create([
            'book_id'          => $physicalBook->id,
            'copy_code'        => Copy::generateCopyCode(),
            'condition'        => 'worn',
            'status'           => 'available',
            'location'         => 'A-03-1-02',
            'is_loanable'      => true,
            'acquired_at'      => '2021-06-10',
            'acquisition_cost' => 45.00,
            'internal_notes'   => 'Lomo reforzado en 2022. Páginas amarillentas.',
        ]);

        // Log de adquisición para ambas copias
        foreach ([$copy1, $copy2] as $copy) {
            CopyConditionLog::create([
                'copy_id'         => $copy->id,
                'to_condition'    => $copy->condition,
                'to_status'       => 'available',
                'changed_by_name' => 'Sistema (Seeder)',
                'context'         => 'acquisition',
                'notes'           => 'Ingreso inicial al inventario.',
            ]);
        }

        $physicalBook->recalculateCopyCounts();

        // =====================================================================
        // LIBRO 2 — E-book (sin copias físicas, licencia ilimitada)
        // =====================================================================
        $ebook = Book::create([
            'title'            => 'Clean Code: A Handbook of Agile Software Craftsmanship',
            'isbn_13'          => '9780132350884',
            'synopsis'         => 'Una guía práctica para escribir código limpio, legible y mantenible. Un clásico de la ingeniería de software.',
            'publisher'        => 'Prentice Hall',
            'publication_year' => 2008,
            'language'         => 'es',
            'pages'            => 431,
            'is_digital'       => true,
            'digital_file_url' => 'https://stream.biblioteca-clasica.pe/ebooks/clean-code-2008.epub',
            // total_copies y available_copies se mantienen en 0 para e-books
        ]);

        $ebook->authors()->attach($martin->id, ['role' => 'primary']);
        $ebook->categories()->sync([$prog->id]);

        // =====================================================================
        // LIBRO 3 — Físico en préstamo activo (para testear el loan-service)
        // =====================================================================
        $book1984 = Book::create([
            'title'            => '1984',
            'isbn_13'          => '9788499890944',
            'synopsis'         => 'Una sociedad totalitaria donde el Gran Hermano lo vigila todo.',
            'publisher'        => 'Debolsillo',
            'publication_year' => 1949,
            'language'         => 'es',
            'pages'            => 312,
            'dewey_code'       => '823.912',
            'location_hint'    => 'Sala B — Estante 01',
            'is_digital'       => false,
        ]);

        $book1984->authors()->attach($orwell->id, ['role' => 'primary']);
        $book1984->categories()->sync([$distopia->id]);

        $copyLoaned = Copy::create([
            'book_id'          => $book1984->id,
            'copy_code'        => Copy::generateCopyCode(),
            'condition'        => 'good',
            'status'           => 'available', // Empieza available para poder transicionar
            'location'         => 'B-01-1-01',
            'is_loanable'      => true,
            'acquired_at'      => '2022-03-20',
            'acquisition_cost' => 38.00,
        ]);

        // Simular que fue prestada (útil para testear el loan-service)
        $copyLoaned->transitionTo(
            newStatus:     'loaned',
            changedByName: 'Sistema (Seeder)',
            context:       'loan_create',
            notes:         'Préstamo de prueba generado por el seeder.'
        );

        $book1984->recalculateCopyCounts();

        $this->command->newLine();
        $this->command->info('✅ Inventory seeder completado.');
        $this->command->table(
            ['Tipo', 'Título', 'Copias', 'Disponibles'],
            [
                ['Físico', $physicalBook->title, 2, 2],
                ['E-book', $ebook->title,        0, '∞ (ilimitado)'],
                ['Físico', $book1984->title,      1, 0],
            ]
        );
    }
}