<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\AddCopiesRequest;
use App\Http\Requests\Catalog\StoreBookRequest;
use App\Http\Requests\Catalog\UpdateBookRequest;
use App\Models\Book;
use App\Models\BookCopy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class BookController extends Controller
{
    public function index(Request $request)
    {
        $query = Book::query()
            ->with('category')
            ->withCount([
                'reservations as active_reservations_count' => function ($q) {
                    $q->whereIn('status', ['pending', 'ready']);
                }
            ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('author', 'ilike', "%{$search}%")
                  ->orWhere('isbn', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->get('sort') === 'popular') {
            $query->orderByDesc('rating_avg');
        } else {
            $query->latest();
        }

        return response()->json($query->paginate($request->input('per_page', 12)));
    }

    public function popular(Request $request)
    {
        $books = Book::withCount('borrows')
            ->with('category')
            ->orderByDesc('borrows_count')
            ->limit($request->get('limit', 6))
            ->get();

        return response()->json($books);
    }

    public function lookupIsbn(Request $request)
    {
        $request->validate(['isbn' => ['required', 'string']]);

        $isbn = preg_replace('/[^0-9Xx]/', '', $request->input('isbn'));

        try {
            $response = Http::timeout(8)->get('https://www.googleapis.com/books/v1/volumes', [
                'q' => "isbn:{$isbn}",
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'មិនអាចភ្ជាប់ទៅ Google Books API បានទេ'], 503);
        }

        if (!$response->successful() || empty($response->json('items'))) {
            return response()->json(['message' => 'រកមិនឃើញសៀវភៅសម្រាប់ ISBN នេះទេ'], 404);
        }

        $info = $response->json('items.0.volumeInfo');

        return response()->json([
            'title'           => $info['title'] ?? '',
            'author'          => implode(', ', $info['authors'] ?? []),
            'publisher'       => $info['publisher'] ?? '',
            'publish_year'    => isset($info['publishedDate']) ? (int) substr($info['publishedDate'], 0, 4) : null,
            'pages'           => $info['pageCount'] ?? null,
            'description'     => $info['description'] ?? '',
            'language'        => $info['language'] ?? 'en',
            'cover_image_url' => $info['imageLinks']['thumbnail'] ?? null,
        ]);
    }

    public function store(StoreBookRequest $request)
    {
        $validated = $request->validated();

        $book = DB::transaction(function () use ($validated, $request) {
            $coverPath = null;
            if ($request->hasFile('cover_image')) {
                $coverPath = $request->file('cover_image')->store('books', 'public');
            }

            $book = Book::create([
                ...collect($validated)->except(['total_qty', 'cover_image'])->toArray(),
                'total_qty'     => $validated['total_qty'],
                'available_qty' => $validated['total_qty'],
                'cover_image'   => $coverPath,
                'status'        => 'available',
            ]);

            $this->generateCopies($book, $validated['total_qty']);
            return $book;
        });

        return response()->json([
            'message' => 'Book created successfully.',
            'book'    => $book->load('category', 'bookCopies'),
        ], 201);
    }

    public function show(Book $book)
    {
        $book->increment('views_count');

        return response()->json(
            $book->load('category', 'bookCopies', 'reviews.member.user')
        );
    }

    public function update(UpdateBookRequest $request, Book $book)
    {
        $validated = $request->validated();

        if ($request->hasFile('cover_image')) {
            if ($book->cover_image) {
                Storage::disk('public')->delete($book->cover_image);
            }
            $validated['cover_image'] = $request->file('cover_image')->store('books', 'public');
        }

        $book->update($validated);

        return response()->json([
            'message' => 'Book updated successfully.',
            'book'    => $book->fresh()->load('category'),
        ]);
    }

    public function addCopies(AddCopiesRequest $request, Book $book)
    {
        $quantity = $request->validated()['quantity'];

        DB::transaction(function () use ($book, $quantity) {
            $this->generateCopies($book, $quantity);
            $book->increment('total_qty', $quantity);
            $book->increment('available_qty', $quantity);
        });

        return response()->json([
            'message' => "Added {$quantity} new copies.",
            'book'    => $book->fresh()->load('bookCopies'),
        ]);
    }

    public function destroy(Book $book)
    {
        if ($book->bookCopies()->where('status', 'borrowed')->exists()) {
            return response()->json(['message' => 'Cannot delete: copies are currently borrowed.'], 422);
        }

        $book->delete();
        return response()->json(['message' => 'Book deleted successfully.']);
    }

    private function generateCopies(Book $book, int $quantity): void
    {
        for ($i = 0; $i < $quantity; $i++) {
            BookCopy::create([
                'book_id'        => $book->id,
                'barcode'        => $this->generateBarcode(),
                'status'         => 'available',
                'condition'      => 'new',
                'shelf_location' => $book->shelf_location,
                'acquired_date'  => $book->acquired_date ?? now(),
            ]);
        }
    }

    private function generateBarcode(): string
    {
        do {
            $code = 'BC-' . date('y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (BookCopy::withTrashed()->where('barcode', $code)->exists());

        return $code;
    }
}