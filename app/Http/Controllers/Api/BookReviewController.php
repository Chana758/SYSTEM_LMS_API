<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookReview;
use App\Models\Member;
use Illuminate\Http\Request;

class BookReviewController extends Controller
{
    public function index(Book $book)
    {
        return response()->json(
            $book->reviews()->with('member.user')->latest()->paginate(10)
        );
    }

    public function store(Request $request, Book $book)
    {
        $validated = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $member = Member::where('user_id', $request->user()->id)->first();

        if (!$member) {
            return response()->json(['message' => 'This user is not a member.'], 403);
        }

        $review = BookReview::updateOrCreate(
            ['book_id' => $book->id, 'member_id' => $member->id],
            $validated
        );

        $this->recalculateRating($book);

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review'  => $review->load('member.user'),
        ], 201);
    }

    private function recalculateRating(Book $book): void
    {
        $stats = $book->reviews()->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')->first();

        $book->update([
            'rating_avg'   => round($stats->avg_rating ?? 0, 2),
            'rating_count' => $stats->total ?? 0,
        ]);
    }
}