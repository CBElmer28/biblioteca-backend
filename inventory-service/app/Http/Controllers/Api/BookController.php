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
            ->allowedFilters(
                AllowedFilter::scope('search'),
                AllowedFilter::exact('language'),
                AllowedFilter::callback('is_digital', fn($q, $v) => 
                    $q->where('is_digital', filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false')
                ),
                AllowedFilter::callback('is_active', fn($q, $v) => 
                    $q->where('is_active', filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false')
                ),
                AllowedFilter::callback('available', fn($q, $v) =>
                    filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->available() : $q
                ),
                AllowedFilter::callback('category_id', fn($q, $v) =>
                    $q->whereHas('categories', fn($c) => $c->where('categories.id', $v))
                ),
                AllowedFilter::callback('author_id', fn($q, $v) =>
                    $q->whereHas('authors', fn($a) => $a->where('authors.id', $v))
                )
            ) 
            ->allowedSorts('title', 'publication_year', 'created_at', 'available_copies') 
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
            $data = $request->validated();

            // Extraemos los valores reales para tenerlos en memoria
            $isDigital = isset($data['is_digital']) ? filter_var($data['is_digital'], FILTER_VALIDATE_BOOLEAN) : null;
            $isActive = isset($data['is_active']) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : null;

            // Forzamos el casteo a nivel de SQL (DB::raw)
            if ($isDigital !== null) {
                $data['is_digital'] = $isDigital ? DB::raw('true') : DB::raw('false');
            }
            if ($isActive !== null) {
                $data['is_active'] = $isActive ? DB::raw('true') : DB::raw('false');
            }

            $book = Book::create($data);

            // Restauramos los valores booleanos nativos en el modelo para evitar que 
            // el objeto DB::raw() se serialice de forma incorrecta hacia Redis o el JSON
            if ($isDigital !== null) $book->is_digital = $isDigital;
            if ($isActive !== null) $book->is_active = $isActive;

            // Autores con roles
            $authorSync = collect($request->authors)
                ->mapWithKeys(fn($a) => [$a['id'] => ['role' => $a['role']]]);
            $book->authors()->sync($authorSync);

            if ($request->filled('categories')) {
                $book->categories()->sync($request->categories);
            }

            DB::commit();

            // Publicar evento para el ecosistema DESPUÉS de asegurar que se guardó en BD
            $bookData = $book->load(['authors', 'categories'])->toArray();
            
            \Illuminate\Support\Facades\Redis::publish(
                config('app.redis_events_channel', 'libreria.events'),
                json_encode([
                    'event'     => 'book.created',
                    'payload'   => $bookData,
                    'source'    => 'inventory-service',
                    'timestamp' => now()->toIso8601String()
                ])
            );

            // Retornamos usando la variable $bookData que ya cargó las relaciones
            return response()->json([
                'success' => true,
                'data'    => $bookData,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar el libro.',
                'debug_error' => $e->getMessage(),
                'line' => $e->getLine()
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

            $updatedBook = $book->fresh(['authors', 'categories']);
            
            \Illuminate\Support\Facades\Redis::publish(
                config('app.redis_events_channel', 'libreria.events'),
                json_encode([
                    'event'     => 'book.updated',
                    'payload'   => $updatedBook->toArray(),
                    'source'    => 'inventory-service',
                    'timestamp' => now()->toIso8601String()
                ])
            );

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

        $glpiId = $book->glpi_id; // Rescatamos el ID antes de la destrucción
        
        $book->delete();

        // Solo notificamos a GLPI si el libro realmente estaba sincronizado (tenía ID)
        if ($glpiId) {
            \Illuminate\Support\Facades\Redis::publish(
                config('app.redis_events_channel', 'libreria.events'),
                json_encode([
                    'event'     => 'book.deleted',
                    'payload'   => ['glpi_id' => $glpiId],
                    'source'    => 'inventory-service',
                    'timestamp' => now()->toIso8601String()
                ])
            );
        }

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

    // ── POST /api/v1/books/{id}/sync ──────────────────────────────────────────
    public function sync(string $id): JsonResponse
    {
        $book = Book::with(['authors', 'categories'])->findOrFail($id);
        
        $event = $book->glpi_id ? 'book.updated' : 'book.created';

        \Illuminate\Support\Facades\Redis::publish(
            config('app.redis_events_channel', 'libreria.events'),
            json_encode([
                'event'     => $event,
                'payload'   => $book->toArray(),
                'source'    => 'inventory-service-manual-sync',
                'timestamp' => now()->toIso8601String()
            ])
        );

        return response()->json([
            'success' => true,
            'message' => 'Sincronización de libro encolada hacia el ecosistema.',
            'event_dispatched' => $event
        ], 202);
    }

    // ── PATCH /api/v1/internal/copies/{id}/glpi-id ────────────────────────────
    public function updateGlpiId(Request $request, string $id): JsonResponse
    {
        // Seguridad: Solo otros microservicios pueden llamar a esto
        if ($request->header('X-Internal-Secret') !== config('app.internal_secret')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'glpi_id' => ['required', 'integer']
        ]);

        $copy = Copy::findOrFail($id);
        $copy->update(['glpi_id' => $data['glpi_id']]);

        return response()->json(['success' => true, 'message' => 'GLPI ID actualizado.']);
    }
}