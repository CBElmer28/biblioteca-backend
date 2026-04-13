<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Copy\StoreCopyRequest;
use App\Models\Book;
use App\Models\Copy;
use Illuminate\Http\JsonResponse;

class CopyController extends Controller
{
    // Registrar una copia física nueva
    public function store(StoreCopyRequest $request): JsonResponse
    {
        $book = Book::findOrFail($request->book_id);

        // REGLA DE NEGOCIO ESTRICTA: No se pueden crear copias de E-books
        if ($book->is_digital) {
            return response()->json([
                'error' => 'Operación inválida. No se pueden registrar copias físicas de un E-book.'
            ], 422);
        }

        $copy = Copy::create($request->validated());

        return response()->json([
            'message' => 'Copia física registrada correctamente.',
            'data' => $copy
        ], 201);
    }
}