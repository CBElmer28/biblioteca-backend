<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Book\StoreBookRequest;
use App\Http\Requests\Book\UpdateBookRequest;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Tymon\JWTAuth\Facades\JWTAuth;

class BookController extends Controller
{
    // ── GET /api/v1/books ──────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $books = QueryBuilder::for(Book::class)
            ->allowedFilters([
                AllowedFilter::scope('search'),
                AllowedFilter::exact('language'),
                AllowedFilter::exact('is_digital'),
                AllowedFilter::exact('is_active'),
                AllowedFilter::callback('available', fn($q, $v) =>
                    $v ? $q->available() : $q
                ),
                AllowedFilter::callback('category_id', fn($q, $v) =>
                    $q->whereHas('categories', fn($c) => $c->where('categories.id', $v))
                ),
                AllowedFilter::callback('author_id', fn($q, $v) =>
                    $q->whereHas('authors', fn($a) => $a->where('authors.id', $v))
                ),
            ])
            ->allowedSorts(['title', 'publication_year', 'created_at', 'available_copies'])
            ->defaultSort('title')
            ->with(['authors:id,name,slug', 'categories:id,name,slug'])
            ->withCount('copies')
            ->active()
            ->paginate(request()->integer('per_page', 20));

        return response()->json(['success' => true, 'data' => $books]);
    }

    // ── GET /api/v1/books/{slug} ───────────────────────────────────────────────
    public function show(string $slug): JsonResponse
    {
        $book = Book::where('slug', $slug)
            ->with([
                'authors',
                'categories',
                // Solo cargar copias para libros físicos
                'copies' => fn($q) => $q->orderBy('status')->orderBy('location'),
            ])
            ->firstOrFail();

        // Los e-books no tienen copies — devolver estructura limpia
        if ($book->is_digital) {
            $book->unsetRelation('copies');
        }

        return response()->json(['success' => true, 'data' => $book]);
    }

    // ── POST /api/v1/books ────────────────────────────────────────────────────
    public function store(StoreBookRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            // Si es físico, limpiar digital_file_url aunque venga en el payload
            $data = $request->except(['authors', 'categories']);
            if (!$request->boolean('is_digital')) {
                $data['digital_file_url'] = null;
            }

            $book = Book::create($data);

            // Autores con roles
            $authorSync = collect($request->authors)
                ->mapWithKeys(fn($a) => [$a['id'] => ['role' => $a['role']]]);
            $book->authors()->sync($authorSync);

            if ($request->filled('categories')) {
                $book->categories()->sync($request->categories);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data'    => $book->load(['authors', 'categories']),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el libro.',
            ], 500);
        }
    }

    // ── PUT /api/v1/books/{id} ────────────────────────────────────────────────
    public function update(UpdateBookRequest $request, string $id): JsonResponse
    {
        $book = Book::findOrFail($id);

        DB::beginTransaction();
        try {
            $book->update($request->except(['authors', 'categories']));

            if ($request->has('authors')) {
                $sync = collect($request->authors)
                    ->mapWithKeys(fn($a) => [$a['id'] => ['role' => $a['role']]]);
                $book->authors()->sync($sync);
            }

            if ($request->has('categories')) {
                $book->categories()->sync($request->categories);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data'    => $book->fresh(['authors', 'categories']),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el libro.',
            ], 500);
        }
    }

    // ── DELETE /api/v1/books/{id} ─────────────────────────────────────────────
    public function destroy(string $id): JsonResponse
    {
        $book = Book::withCount([
            'copies as active_copies_count' => fn($q) =>
                $q->whereIn('status', ['available', 'loaned', 'reserved']),
        ])->findOrFail($id);

        if ($book->active_copies_count > 0) {
            return response()->json([
                'success' => false,
                'message' => "No se puede eliminar: el libro tiene {$book->active_copies_count} ejemplar(es) activo(s).",
            ], 409);
        }

        $book->delete();

        return response()->json([
            'success' => true,
            'message' => 'Libro dado de baja del catálogo.',
        ]);
    }

    // ── GET /api/v1/books/available — Solo libros disponibles para préstamo ────
    public function available(Request $request): JsonResponse
    {
        $books = Book::active()
            ->when(
                $request->boolean('digital_only'),
                fn($q) => $q->digital(),
                fn($q) => $q->when(
                    $request->boolean('physical_only'),
                    fn($q2) => $q2->physical()->available()
                )
            )
            ->with(['authors:id,name', 'categories:id,name'])
            ->orderBy('title')
            ->paginate(request()->integer('per_page', 20));

        return response()->json(['success' => true, 'data' => $books]);
    }
}