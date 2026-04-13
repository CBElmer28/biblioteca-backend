<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Author;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    // GET /api/v1/authors
    public function index(): JsonResponse
    {
        $authors = Author::withCount('books')
            ->orderBy('name')
            ->paginate(request('per_page', 20));

        return response()->json(['success' => true, 'data' => $authors]);
    }

    // GET /api/v1/authors/{slug}
    public function show(string $slug): JsonResponse
    {
        $author = Author::where('slug', $slug)
            ->with(['books' => fn($q) => $q->active()->limit(12)])
            ->withCount('books')
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $author]);
    }

    // POST /api/v1/authors
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'biography'   => ['nullable', 'string', 'max:5000'],
            'photo_url'   => ['nullable', 'url'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'birth_date'  => ['nullable', 'date'],
            'website'     => ['nullable', 'url'],
        ]);

        $author = Author::create($data);
        return response()->json(['success' => true, 'data' => $author], 201);
    }

    // PUT /api/v1/authors/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $author = Author::findOrFail($id);
        $author->update($request->validate([
            'name'        => ['sometimes', 'string', 'max:150'],
            'biography'   => ['sometimes', 'nullable', 'string'],
            'photo_url'   => ['sometimes', 'nullable', 'url'],
            'nationality' => ['sometimes', 'nullable', 'string'],
            'birth_date'  => ['sometimes', 'nullable', 'date'],
            'website'     => ['sometimes', 'nullable', 'url'],
        ]));

        return response()->json(['success' => true, 'data' => $author]);
    }
}