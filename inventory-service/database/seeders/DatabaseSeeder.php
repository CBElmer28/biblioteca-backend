<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Author;
use App\Models\Book;
use App\Models\Copy;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear Categorías
        $literatura = Category::create([
            'name' => 'Literatura Clásica',
            'description' => 'Obras literarias de gran valor histórico.'
        ]);

        $novela = Category::create([
            'name' => 'Novela',
            'parent_id' => $literatura->id, 
            'description' => 'Narrativas extensas.'
        ]);

        // 2. Crear Autores
        $garciaMarquez = Author::create([
            'name' => 'Gabriel García Márquez',
            'nationality' => 'Colombiana',
        ]);

        $georgeOrwell = Author::create([
            'name' => 'George Orwell',
            'nationality' => 'Británica',
        ]);

        // 3. Crear Libro FÍSICO (Simulando que ya fue sincronizado con GLPI)
        $libroFisico = Book::create([
            'title' => 'Cien años de soledad',
            'synopsis' => 'La historia de la familia Buendía en Macondo.',
            'publisher' => 'Editorial Sudamericana',
            'publication_year' => 1967,
            'is_digital' => false,
            'glpi_id' => null, // <-- CÁMBIALO A NULL
        ]);
        
        $libroFisico->authors()->attach($garciaMarquez->id, ['role' => 'primary']);
        $libroFisico->categories()->attach($novela->id);

        // Crear copias físicas vinculadas al libro y a GLPI
        Copy::create([
            'book_id' => $libroFisico->id,
            'copy_code' => Copy::generateCopyCode(),
            'condition' => 'new',
            'status' => 'available',
            'location' => 'Sala A - Estante 1',
            'glpi_id' => null, // <-- CÁMBIALO A NULL
        ]);
        
        Copy::create([
            'book_id' => $libroFisico->id,
            'copy_code' => Copy::generateCopyCode(),
            'condition' => 'good',
            'status' => 'available',
            'location' => 'Sala A - Estante 1',
            'glpi_id' => 102, // <-- SIMULACIÓN: ID de esta copia en GLPI
        ]);

        $libroFisico->recalculateCopyCounts();

        // 4. Crear E-BOOK (Digital)
        $eBook = Book::create([
            'title' => '1984',
            'synopsis' => 'Una novela distópica sobre la vigilancia del gobierno.',
            'publisher' => 'Secker & Warburg',
            'publication_year' => 1949,
            'is_digital' => true, 
            'digital_file_url' => 'https://biblioteca.local/archivos/1984.pdf',
            'glpi_id' => null, // <-- Los e-books no son activos físicos ITAM
        ]);

        $eBook->authors()->attach($georgeOrwell->id, ['role' => 'primary']);
        $eBook->categories()->attach($novela->id);
    }
}