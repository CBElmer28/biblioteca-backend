<?php

namespace Database\Seeders;

use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // Categorías principales de una librería clásica
        // ---------------------------------------------------------------
        $categories = [
            ['name' => 'Literatura',       'children' => ['Novela', 'Cuento', 'Poesía', 'Teatro']],
            ['name' => 'Ciencia Ficción',  'children' => ['Space Opera', 'Cyberpunk', 'Distopía']],
            ['name' => 'Historia',         'children' => ['Historia Universal', 'Historia del Perú', 'Biografías']],
            ['name' => 'Ciencia',          'children' => ['Física', 'Biología', 'Astronomía']],
            ['name' => 'Tecnología',       'children' => ['Programación', 'Inteligencia Artificial', 'DevOps']],
            ['name' => 'Filosofía',        'children' => ['Filosofía Clásica', 'Ética', 'Epistemología']],
            ['name' => 'Autoayuda',        'children' => ['Productividad', 'Finanzas Personales']],
        ];

        foreach ($categories as $catData) {
            $parent = Category::create([
                'name'      => $catData['name'],
                'is_active' => true,
            ]);

            foreach ($catData['children'] as $childName) {
                Category::create([
                    'name'      => $childName,
                    'parent_id' => $parent->id,
                    'is_active' => true,
                ]);
            }
        }

        // ---------------------------------------------------------------
        // Autores de muestra
        // ---------------------------------------------------------------
        $authors = [
            ['name' => 'Mario Vargas Llosa',   'nationality' => 'PE'],
            ['name' => 'Gabriel García Márquez','nationality' => 'CO'],
            ['name' => 'Jorge Luis Borges',     'nationality' => 'AR'],
            ['name' => 'Robert C. Martin',      'nationality' => 'US'],
            ['name' => 'Martin Fowler',         'nationality' => 'GB'],
        ];

        foreach ($authors as $authorData) {
            Author::create($authorData);
        }

        // ---------------------------------------------------------------
        // Libros de muestra (10 libros representativos)
        // ---------------------------------------------------------------
        $novela = Category::where('name', 'Novela')->first();
        $prog   = Category::where('name', 'Programación')->first();
        $vll    = Author::where('name', 'Mario Vargas Llosa')->first();
        $rcm    = Author::where('name', 'Robert C. Martin')->first();

        $books = [
            [
                'title'    => 'La Ciudad y los Perros',
                'synopsis' => 'Una novela que retrata la vida en el Colegio Militar Leoncio Prado de Lima.',
                'price'    => 45.00,
                'stock'    => 30,
                'author_id'=> $vll->id,
                'status'   => 'active',
                'categories'=> [$novela->id],
            ],
            [
                'title'    => 'Clean Code',
                'synopsis' => 'Guía práctica para escribir código limpio, legible y mantenible.',
                'price'    => 89.90,
                'stock'    => 15,
                'author_id'=> $rcm->id,
                'status'   => 'active',
                'is_featured' => true,
                'categories'=> [$prog->id],
            ],
        ];

        foreach ($books as $bookData) {
            $categories = $bookData['categories'] ?? [];
            unset($bookData['categories']);

            $book = Book::create($bookData);
            $book->categories()->sync($categories);
        }

        $this->command->info('✅ Catalog seeder completado: categorías, autores y libros de muestra creados.');
    }
}