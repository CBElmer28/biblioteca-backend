<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Sluggable\SlugOptions;

class CategoryController extends Controller
{
    // GET /api/v1/categories — Árbol completo de categorías
    public function index(): JsonResponse
    {
        // Solo categorías raíz con sus hijos anidados
        $categories = Category::with('childrenRecursive')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    // GET /api/v1/categories/{slug}/books — Libros de una categoría
    public function books(string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)->with('children')->firstOrFail();

        // Incluir libros de subcategorías hijas también
        $categoryIds = $category->children->pluck('id')->push($category->id);

        $books = \App\Models\Book::active()
            ->whereHas('categories', fn($q) => $q->whereIn('categories.id', $categoryIds))
            ->with(['author', 'primaryImage'])
            ->paginate(request('per_page', 20));

        return response()->json([
            'success'  => true,
            'category' => $category,
            'data'     => $books,
        ]);
    }

    // POST /api/v1/categories
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100', 'unique:categories,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'image_url'   => ['nullable', 'url'],
            'parent_id'   => ['nullable', 'integer', 'exists:categories,id'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
        ]);

        $category = Category::create($data);

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    // PUT /api/v1/categories/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_url'   => ['sometimes', 'nullable', 'url'],
            'parent_id'   => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'is_active'   => ['sometimes', 'boolean'],
            'sort_order'  => ['sometimes', 'integer', 'min:0'],
        ]);

        $category->update($data);
        return response()->json(['success' => true, 'data' => $category]);
    }
}