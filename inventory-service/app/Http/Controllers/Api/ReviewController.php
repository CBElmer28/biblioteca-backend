<?php

namespace App\Http\Controllers\Api;

// =============================================================================
// ReviewController — Reseñas de libros con moderación
// Las reseñas quedan en estado "pending" hasta que un admin las aprueba.
// =============================================================================

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Models\Book;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class ReviewController extends Controller
{
    // GET /api/v1/books/{bookId}/reviews
    public function index(int $bookId): JsonResponse
    {
        $book = Book::findOrFail($bookId);

        $reviews = $book->reviews()  // Solo aprobadas (scope del model)
            ->orderBy('helpful_votes', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(request('per_page', 10));

        // Distribución de ratings (1-5 estrellas)
        $ratingDistribution = Review::where('book_id', $bookId)
            ->where('status', 'approved')
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->pluck('count', 'rating');

        return response()->json([
            'success'              => true,
            'data'                 => $reviews,
            'average_rating'       => $book->average_rating,
            'rating_distribution'  => $ratingDistribution,
        ]);
    }

    // POST /api/v1/books/{bookId}/reviews
    public function store(StoreReviewRequest $request, int $bookId): JsonResponse
    {
        $book = Book::findOrFail($bookId);
        $user = JWTAuth::user();

        // Verificar que no exista ya una reseña del mismo usuario para este libro
        $exists = Review::where('book_id', $bookId)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Ya tienes una reseña para este libro.',
            ], 409);
        }

        $review = Review::create([
            'book_id'    => $bookId,
            'user_id'    => $user->id,
            'user_name'  => $user->name,  // Desnormalizado para no cruzar servicios
            'rating'     => $request->rating,
            'title'      => $request->title,
            'body'       => $request->body,
            'status'     => 'pending',    // Requiere moderación
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reseña enviada. Será publicada tras revisión.',
            'data'    => $review,
        ], 201);
    }

    // PATCH /api/v1/reviews/{id}/moderate — Solo admins
    public function moderate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status'           => ['required', 'in:approved,rejected'],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string'],
        ]);

        $review = Review::findOrFail($id);
        $review->update($data);  // El Observer del model recalcula el rating del libro

        return response()->json([
            'success' => true,
            'message' => "Reseña {$data['status']}.",
            'data'    => $review,
        ]);
    }

    // POST /api/v1/reviews/{id}/helpful — Marcar reseña como útil
    public function markHelpful(int $id): JsonResponse
    {
        $review = Review::where('id', $id)->where('status', 'approved')->firstOrFail();
        $review->increment('helpful_votes');

        return response()->json([
            'success'       => true,
            'helpful_votes' => $review->helpful_votes,
        ]);
    }

    // GET /api/v1/reviews/pending — Cola de moderación (solo admin)
    public function pending(): JsonResponse
    {
        $reviews = Review::where('status', 'pending')
            ->with('book:id,title,slug')
            ->oldest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $reviews]);
    }
}