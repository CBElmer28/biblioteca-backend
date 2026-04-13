<?php

namespace App\Http\Controllers\Api;

// =============================================================================
// BookController — CRUD de libros con búsqueda, filtros y paginación
// Usa spatie/laravel-query-builder para filtros declarativos y seguros.
// =============================================================================

use App\Http\Controllers\Controller;
use App\Http\Requests\Book\StoreBookRequest;
use App\Models\Book;
use App\Models\PriceHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Tymon\JWTAuth\Facades\JWTAuth;

class BookController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/v1/books
    // Filtros: ?filter[category_id]=1&filter[author_id]=2&filter[status]=active
    // Búsqueda: ?filter[search]=clean+code
    // Ordenar: ?sort=price,-average_rating (- = descendente)
    // Rango precio: ?filter[min_price]=10&filter[max_price]=50
    // -------------------------------------------------------------------------
    public function index(): JsonResponse
    {
        $books = QueryBuilder::for(Book::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('author_id'),
                AllowedFilter::exact('language'),
                AllowedFilter::exact('format'),
                AllowedFilter::exact('is_featured'),
                AllowedFilter::scope('search'),       // Usa scopeSearch del model

                // Filtros de rango de precio
                AllowedFilter::callback('min_price', fn($q, $v) =>
                    $q->where('price_after_discount', '>=', $v)
                ),
                AllowedFilter::callback('max_price', fn($q, $v) =>
                    $q->where('price_after_discount', '<=', $v)
                ),

                // Filtrar por categoría (relación many-to-many)
                AllowedFilter::callback('category_id', fn($q, $v) =>
                    $q->whereHas('categories', fn($c) => $c->where('categories.id', $v))
                ),
            ])
            ->allowedSorts([
                'price', 'price_after_discount', 'average_rating',
                'reviews_count', 'sales_count', 'published_at', 'created_at',
            ])
            ->defaultSort('-created_at')
            ->with(['author', 'categories', 'primaryImage'])
            ->withCount('reviews')
            ->paginate(request('per_page', 20));

        return response()->json(['success' => true, 'data' => $books]);
    }

    // GET /api/v1/books/{slug}
    public function show(string $slug): JsonResponse
    {
        $book = Book::where('slug', $slug)
            ->with([
                'author',
                'categories',
                'images',
                'reviews' => fn($q) => $q->latest()->limit(10),
                'priceHistory' => fn($q) => $q->limit(5),
            ])
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $book]);
    }

    // POST /api/v1/books
    public function store(StoreBookRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $book = Book::create($request->except('categories'));

            // Sincronizar categorías (pivot)
            if ($request->categories) {
                $book->categories()->sync($request->categories);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'data'    => $book->load(['author', 'categories']),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear el libro.'], 500);
        }
    }

    // PUT /api/v1/books/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $book = Book::findOrFail($id);

        $data = $request->validate([
            'title'               => ['sometimes', 'string', 'max:255'],
            'synopsis'            => ['sometimes', 'nullable', 'string'],
            'price'               => ['sometimes', 'numeric', 'min:0.01'],
            'discount_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'stock'               => ['sometimes', 'integer', 'min:0'],
            'stock_threshold'     => ['sometimes', 'integer', 'min:0'],
            'status'              => ['sometimes', 'in:draft,active,out_of_stock,discontinued'],
            'is_featured'         => ['sometimes', 'boolean'],
            'categories'          => ['sometimes', 'array'],
            'categories.*'        => ['integer', 'exists:categories,id'],
        ]);

        DB::beginTransaction();
        try {
            // Registrar historial si el precio cambió
            if (isset($data['price']) && $data['price'] != $book->price) {
                PriceHistory::create([
                    'book_id'              => $book->id,
                    'old_price'            => $book->price,
                    'new_price'            => $data['price'],
                    'old_discount'         => $book->discount_percentage,
                    'new_discount'         => $data['discount_percentage'] ?? $book->discount_percentage,
                    'changed_by_user_id'   => JWTAuth::user()?->id,
                    'reason'               => $request->price_change_reason,
                    'changed_at'           => now(),
                ]);
            }

            $book->update($data);

            if (isset($data['categories'])) {
                $book->categories()->sync($data['categories']);
            }

            DB::commit();
            return response()->json(['success' => true, 'data' => $book->load(['author', 'categories'])]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar.'], 500);
        }
    }

    // DELETE /api/v1/books/{id} — Soft delete
    public function destroy(int $id): JsonResponse
    {
        Book::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Libro eliminado del catálogo.']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/books/featured — Libros destacados (carrusel homepage)
    // -------------------------------------------------------------------------
    public function featured(): JsonResponse
    {
        $books = Book::active()
            ->inStock()
            ->featured()
            ->with(['author', 'primaryImage', 'categories'])
            ->orderBy('sales_count', 'desc')
            ->limit(10)
            ->get();

        return response()->json(['success' => true, 'data' => $books]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/books/{id}/stock — Actualizar stock (recepción de mercadería)
    // -------------------------------------------------------------------------
    public function updateStock(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'quantity'  => ['required', 'integer'],  // Positivo = entrada, negativo = ajuste
            'reason'    => ['required', 'string', 'max:255'],
        ]);

        $book = Book::findOrFail($id);
        $newStock = max(0, $book->stock + $request->quantity);

        $book->update([
            'stock'  => $newStock,
            'status' => $newStock > 0 ? 'active' : 'out_of_stock',
        ]);

        return response()->json([
            'success'    => true,
            'message'    => 'Stock actualizado.',
            'book_id'    => $book->id,
            'new_stock'  => $newStock,
            'available'  => $book->available_stock,
        ]);
    }
}