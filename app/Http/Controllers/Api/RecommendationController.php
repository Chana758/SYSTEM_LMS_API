<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BorrowTransaction;
use App\Models\Recommendation;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    private const LIMIT = 10;

    public function index(Request $request)
    {
        $user = $request->user();
        $member = $user->member;

        // Staff accounts (admin/librarian) have no borrowing history to
        // base a personal recommendation on — show popular books instead
        // of an empty screen, but label it clearly so it's obvious this
        // isn't personalized.
        if (! $member) {
            $books = Book::orderByDesc('views_count')
                ->orderByDesc('rating_avg')
                ->limit(self::LIMIT)
                ->get();

            return response()->json([
                'data' => $books,
                'reason' => 'popular_no_member',
                'reason_label' => 'Popular in the library — personalized picks need a member profile',
            ]);
        }

        $borrowedBookIds = BorrowTransaction::where('member_id', $member->id)
            ->join('book_copies', 'borrow_transactions.book_copy_id', '=', 'book_copies.id')
            ->pluck('book_copies.book_id')
            ->unique()
            ->values();

        $categoryIds = Book::whereIn('id', $borrowedBookIds)
            ->pluck('category_id')
            ->unique()
            ->values();

        if ($categoryIds->isEmpty()) {

            $books = Book::orderByDesc('views_count')
                ->orderByDesc('rating_avg')
                ->limit(self::LIMIT)
                ->get();

            return response()->json([
                'data' => $books,
                'reason' => 'popular',
                'reason_label' => 'Popular in the library',
            ]);
        }
        $books = Book::whereIn('category_id', $categoryIds)
            ->whereNotIn('id', $borrowedBookIds)
            ->orderByDesc('rating_avg')
            ->orderByDesc('views_count')
            ->limit(self::LIMIT)
            ->get();

        if ($books->count() < self::LIMIT) {
            $excludeIds = $borrowedBookIds->merge($books->pluck('id'));
            $filler = Book::whereNotIn('id', $excludeIds)
                ->orderByDesc('views_count')
                ->limit(self::LIMIT - $books->count())
                ->get();
            $books = $books->concat($filler);
        }

        return response()->json([
            'data' => $books,
            'reason' => 'content_based',
            'reason_label' => 'Based on your reading history',
        ]);
    }

    public function markClicked(Request $request, Book $book)
    {
        Recommendation::updateOrCreate(
            ['user_id' => $request->user()->id, 'book_id' => $book->id],
            ['is_clicked' => true]
        );

        return response()->json(['message' => 'Recorded.']);
    }
}