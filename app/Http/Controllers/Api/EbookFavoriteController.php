<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ebook;
use App\Models\EbookFavorite;
use Illuminate\Http\Request;

class EbookFavoriteController extends Controller
{
    /**
     * List the authenticated user's favorited e-books.
     */
    public function index(Request $request)
    {
        $favorites = EbookFavorite::with('ebook.book.category')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($request->get('per_page', 12));

        return response()->json($favorites);
    }

    /**
     * Toggle favorite status for an e-book.
     */
    public function toggle(Request $request, Ebook $ebook)
    {
        $existing = EbookFavorite::where('user_id', $request->user()->id)
            ->where('ebook_id', $ebook->id)
            ->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['message' => 'Removed from favorites.', 'is_favorited' => false]);
        }

        EbookFavorite::create([
            'user_id'  => $request->user()->id,
            'ebook_id' => $ebook->id,
        ]);

        return response()->json(['message' => 'Added to favorites.', 'is_favorited' => true]);
    }
}