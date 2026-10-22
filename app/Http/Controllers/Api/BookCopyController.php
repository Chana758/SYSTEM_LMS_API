<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\UpdateBookCopyRequest;
use App\Models\Book;
use App\Models\BookCopy;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookCopyController extends Controller
{
    /** Inject the reservation service used to promote the waiting queue. */
    public function __construct(protected ReservationService $reservations)
    {
    }

    /** Update a copy (status, condition, notes), log the status change, and sync book stock. */
    public function update(UpdateBookCopyRequest $request, BookCopy $copy)
    {
        return DB::transaction(function () use ($request, $copy) {
            $copy = BookCopy::whereKey($copy->id)->lockForUpdate()->firstOrFail();
            $data = $request->validated();

            if ($copy->status === 'borrowed' && isset($data['status'])) {
                return response()->json([
                    'message' => 'This copy is currently borrowed. Return it first.',
                ], 422);
            }

            $oldStatus = $copy->status;
            $reason = $data['reason'] ?? null;
            unset($data['reason']);

            if (isset($data['status']) && $data['status'] !== $oldStatus) {
                // Keep the physical condition in step with the status
                if (in_array($data['status'], ['damaged', 'lost'], true) && ! isset($data['condition'])) {
                    $data['condition'] = $data['status'];
                }

                $line = sprintf(
                    '[%s] %s: %s -> %s%s',
                    now()->format('Y-m-d H:i'),
                    $request->user()->name,
                    $oldStatus,
                    $data['status'],
                    $reason ? " ({$reason})" : ''
                );
                $base = array_key_exists('notes', $data) ? $data['notes'] : $copy->notes;
                $data['notes'] = trim(($base ? $base . "\n" : '') . $line);
            }

            $copy->update($data);
            $this->syncBookQty($copy->book_id);

            if ($oldStatus !== 'available' && $copy->status === 'available') {
                $this->reservations->promoteNextInQueue($copy->book_id);
            }

            return response()->json([
                'message' => 'Copy updated successfully.',
                'data'    => $copy->fresh(),
            ]);
        });
    }

    /** Remove a copy (soft delete) unless it is currently borrowed, then sync book stock. */
    public function destroy(Request $request, BookCopy $copy)
    {
        return DB::transaction(function () use ($copy) {
            $copy = BookCopy::whereKey($copy->id)->lockForUpdate()->firstOrFail();

            $hasActiveBorrow = $copy->transactions()->where('status', 'borrowed')->exists();

            if ($copy->status === 'borrowed' || $hasActiveBorrow) {
                return response()->json([
                    'message' => 'This copy is currently borrowed and cannot be removed.',
                ], 422);
            }

            $bookId = $copy->book_id;
            $copy->delete(); // soft delete keeps borrow history
            $this->syncBookQty($bookId);

            return response()->json(['message' => 'Copy removed successfully.']);
        });
    }

    /** Recalculate the book's total and available quantities from its real copies. */
    private function syncBookQty(int $bookId): void
    {
        Book::whereKey($bookId)->update([
            'total_qty'     => BookCopy::where('book_id', $bookId)->count(),
            'available_qty' => BookCopy::where('book_id', $bookId)->where('status', 'available')->count(),
        ]);
    }
}