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
        // 1. Crear Categorías (Usando UUIDs para el parent_id)
        $literatura = Category::create([
            'name' => 'Literatura Clásica',
            'description' => 'Obras literarias de gran valor histórico.'
        ]);

        $novela = Category::create([
            'name' => 'Novela',
            'parent_id' => $literatura->id, // <-- AQUÍ usamos el UUID dinámico, no un '1'
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

        // 3. Crear Libro FÍSICO
        $libroFisico = Book::create([
            'title' => 'Cien años de soledad',
            'synopsis' => 'La historia de la familia Buendía en Macondo.',
            'publisher' => 'Editorial Sudamericana',
            'publication_year' => 1967,
            'is_digital' => false, // Es físico
        ]);
        
        // Relacionar libro físico con autor y categoría (Eloquent maneja los UUIDs en la tabla pivot)
        $libroFisico->authors()->attach($garciaMarquez->id, ['role' => 'primary']);
        $libroFisico->categories()->attach($novela->id);

        // Crear copias físicas para este libro
        Copy::create([
            'book_id' => $libroFisico->id,
            'copy_code' => Copy::generateCopyCode(),
            'condition' => 'new',
            'status' => 'available',
            'location' => 'Sala A - Estante 1'
        ]);
        
        Copy::create([
            'book_id' => $libroFisico->id,
            'copy_code' => Copy::generateCopyCode(),
            'condition' => 'good',
            'status' => 'available',
            'location' => 'Sala A - Estante 1'
        ]);

        // Actualizar contadores del libro físico
        $libroFisico->recalculateCopyCounts();

        // 4. Crear E-BOOK (Digital)
        $eBook = Book::create([
            'title' => '1984',
            'synopsis' => 'Una novela distópica sobre la vigilancia del gobierno.',
            'publisher' => 'Secker & Warburg',
            'publication_year' => 1949,
            'is_digital' => true, // Es digital
            'digital_file_url' => 'https://biblioteca.local/archivos/1984.pdf'
        ]);

        $eBook->authors()->attach($georgeOrwell->id, ['role' => 'primary']);
        $eBook->categories()->attach($novela->id);
        
        // REGLA HÍBRIDA: Los E-books NO tienen copias en la tabla copies.
    }
}