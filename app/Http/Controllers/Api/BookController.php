<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\AddCopiesRequest;
use App\Http\Requests\Catalog\StoreBookRequest;
use App\Http\Requests\Catalog\UpdateBookRequest;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BookController extends Controller
{
    /** Inject the reservation service used to promote the waiting queue. */
    public function __construct(protected ReservationService $reservations)
    {
    }

    /** List books with search, category/status filters, sorting and pagination. */
    public function index(Request $request)
    {
        $query = Book::query()
            ->with('category')
            ->withCount([
                'reservations as active_reservations_count' => function ($q) {
                    $q->whereIn('status', Reservation::ACTIVE);
                },
            ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            // ilike = PostgreSQL. If your DB is MySQL, change both to 'like'.
            $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function ($q) use ($search, $op) {
                $q->where('title', $op, "%{$search}%")
                  ->orWhere('author', $op, "%{$search}%")
                  ->orWhere('isbn', $op, "%{$search}%");
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

        $perPage = min((int) $request->input('per_page', 12), 100);

        return response()->json($query->paginate($perPage));
    }

    /** Return the most borrowed books (default 6, max 50). */
    public function popular(Request $request)
    {
        $limit = min((int) $request->get('limit', 6), 50);

        $books = Book::withCount('borrows')
            ->with('category')
            ->orderByDesc('borrows_count')
            ->limit($limit)
            ->get();

        return response()->json($books);
    }

    /** Create a book with its cover image and generate its physical copies. */
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

    /** Show one book with details and count the view. */
    public function show(Book $book)
    {
        $book->increment('views_count');

        return response()->json(
            $book->load('category', 'bookCopies', 'reviews.member.user')
        );
    }

    /** Update a book and replace its cover image if a new one is uploaded. */
    public function update(UpdateBookRequest $request, Book $book)
    {
        $validated = $request->validated();

        if ($request->hasFile('cover_image')) {
            if ($book->cover_image) {
                Storage::disk('public')->delete($book->cover_image);
            }
            $validated['cover_image'] = $request->file('cover_image')->store('books', 'public');
        } else {
            unset($validated['cover_image']);
        }

        $book->update($validated);

        return response()->json([
            'message' => 'Book updated successfully.',
            'book'    => $book->fresh()->load('category'),
        ]);
    }

    /** Add more copies to a book, refresh its stock, and serve waiting reservations. */
    public function addCopies(AddCopiesRequest $request, Book $book)
    {
        $quantity = $request->validated()['quantity'];

        DB::transaction(function () use ($book, $quantity) {
            $this->generateCopies($book, $quantity);

            // Recalculate from real copies instead of blind increments.
            $book->update([
                'total_qty'     => BookCopy::where('book_id', $book->id)->count(),
                'available_qty' => BookCopy::where('book_id', $book->id)->where('status', 'available')->count(),
            ]);

            // New copies may serve members who are waiting in the queue.
            $this->reservations->promoteNextInQueue($book->id);
        });

        return response()->json([
            'message' => "Added {$quantity} new copies.",
            'book'    => $book->fresh()->load('bookCopies'),
        ]);
    }

    /** Delete a book unless copies are borrowed or active reservations exist. */
    public function destroy(Book $book)
    {
        if ($book->bookCopies()->where('status', 'borrowed')->exists()) {
            return response()->json(['message' => 'Cannot delete: copies are currently borrowed.'], 422);
        }

        // Do not leave members waiting for a book that no longer exists.
        if ($book->reservations()->whereIn('status', Reservation::ACTIVE)->exists()) {
            return response()->json([
                'message' => 'Cannot delete: members have active reservations for this book. Cancel them first.',
            ], 422);
        }

        $book->delete();

        return response()->json(['message' => 'Book deleted successfully.']);
    }

    /** Create the given number of new, available copies for a book. */
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

    /** Generate a unique copy barcode like BC-26-000123 (ISBN is never generated). */
    private function generateBarcode(): string
    {
        do {
            $code = 'BC-' . date('y') . '-' . str_pad(random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (BookCopy::withTrashed()->where('barcode', $code)->exists());

        return $code;
    }
}