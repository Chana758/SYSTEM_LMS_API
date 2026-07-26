<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Circulation\StoreBorrowRequest;
use App\Models\BookCopy;
use App\Models\BorrowTransaction;
use App\Models\Fine;
use App\Models\Librarian;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Reservation;
use App\Support\CurrencyHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BorrowController extends Controller
{
    protected int $borrowDays = 14;
    protected int $finePerDay = 500; // ៛/day — បំលែងទៅ USD ត្រង់ returnBook()
    protected int $maxRenewals = 2;
    protected int $renewDays = 7;

    public function index(Request $request)
    {
        $query = BorrowTransaction::with(['member.user', 'bookCopy.book.category', 'librarian.user', 'fines'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('member.user', fn ($qq) => $qq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('bookCopy.book', fn ($qq) => $qq->where('title', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('borrow_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('borrow_date', '<=', $request->to);
        }

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function overdue(Request $request)
    {
        $query = BorrowTransaction::with(['member.user', 'bookCopy.book.category'])
            ->where('status', 'borrowed')
            ->where('due_date', '<', now())
            ->orderBy('due_date');

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function myBorrows(Request $request)
    {
        $member = Member::where('user_id', $request->user()->id)->firstOrFail();

        $borrows = BorrowTransaction::with(['bookCopy.book.category', 'fines'])
            ->where('member_id', $member->id)
            ->latest()
            ->paginate($request->get('per_page', 15));

        return response()->json($borrows);
    }

    public function store(StoreBorrowRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $member = Member::findOrFail($request->member_id);

            $alreadyBorrowed = BorrowTransaction::where('member_id', $member->id)
                ->whereHas('bookCopy', fn ($q) => $q->where('book_id', $request->book_id))
                ->where('status', 'borrowed')
                ->exists();

            if ($alreadyBorrowed) {
                return response()->json(['message' => 'This member has already borrowed this book.'], 422);
            }

            $bookCopy = BookCopy::where('book_id', $request->book_id)
                ->where('status', 'available')
                ->lockForUpdate()
                ->first();

            if (!$bookCopy) {
                return response()->json(['message' => 'This book is currently out of stock.'], 422);
            }

            $ownReservation = Reservation::where('book_id', $request->book_id)
                ->where('member_id', $member->id)
                ->where('status', 'ready')
                ->first();

            $blockedByOther = Reservation::where('book_id', $request->book_id)
                ->where('status', 'ready')
                ->where('member_id', '!=', $member->id)
                ->exists();

            if ($blockedByOther && !$ownReservation) {
                return response()->json([
                    'message' => 'This copy is reserved for another member. Please fulfill the reservation queue first.',
                ], 422);
            }

            $librarian = Librarian::where('user_id', $request->user()->id)->first();

            $borrow = BorrowTransaction::create([
                'member_id'     => $member->id,
                'book_copy_id'  => $bookCopy->id,
                'librarian_id'  => $librarian?->id,
                'borrow_date'   => now(),
                'due_date'      => now()->addDays($this->borrowDays),
                'status'        => 'borrowed',
                'renewed_count' => 0,
            ]);

            $bookCopy->update(['status' => 'borrowed']);
            $bookCopy->book()->decrement('available_qty');

            if ($ownReservation) {
                $ownReservation->update(['status' => 'fulfilled']);
            }

            return response()->json([
                'message' => 'Book borrowed successfully.',
                'data'    => $borrow->load(['member.user', 'bookCopy.book.category']),
            ], 201);
        });
    }

    public function show(BorrowTransaction $borrow)
    {
        return response()->json($borrow->load(['member.user', 'bookCopy.book.category', 'librarian.user', 'fines']));
    }

    public function renew(Request $request, BorrowTransaction $borrow)
    {
        $user = $request->user();

        if ($user->role?->name === 'member') {
            $member = Member::where('user_id', $user->id)->firstOrFail();
            if ($borrow->member_id !== $member->id) {
                return response()->json(['message' => 'You are not authorized to renew this borrow.'], 403);
            }
        }

        if ($borrow->status !== 'borrowed') {
            return response()->json(['message' => 'Only active borrows can be renewed.'], 422);
        }

        if (Carbon::parse($borrow->due_date)->isPast()) {
            return response()->json(['message' => 'Overdue books cannot be renewed. Please return and pay fines first.'], 422);
        }

        if ($borrow->renewed_count >= $this->maxRenewals) {
            return response()->json(['message' => "Maximum renewal limit ({$this->maxRenewals}) reached."], 422);
        }

        $hasQueuedReservation = Reservation::where('book_id', $borrow->bookCopy->book_id)
            ->whereIn('status', ['pending', 'ready'])
            ->exists();

        if ($hasQueuedReservation) {
            return response()->json(['message' => 'Cannot renew — another member is waiting for this book.'], 422);
        }

        $borrow->update([
            'due_date'      => Carbon::parse($borrow->due_date)->addDays($this->renewDays),
            'renewed_count' => $borrow->renewed_count + 1,
        ]);

        return response()->json([
            'message' => 'Book renewed successfully.',
            'data'    => $borrow->fresh()->load(['member.user', 'bookCopy.book.category']),
        ]);
    }

    /**
     * FIX: All fine amounts (overdue, damaged, lost) are now converted
     * from Riel to USD via CurrencyHelper::khrToUsd() at the ONE place
     * they're created — the backend. Previously overdue fines were never
     * converted at all (500៛/day stored as $500/day), and damaged/lost
     * fines relied on the frontend to pre-convert, which was fragile and
     * inconsistent (a direct API call, e.g. from Postman or a future
     * integration, would bypass the frontend conversion entirely and
     * store raw Riel as USD again).
     */
    public function returnBook(Request $request, BorrowTransaction $borrow)
    {
        if ($borrow->status === 'returned') {
            return response()->json(['message' => 'This book has already been returned.'], 422);
        }

        return DB::transaction(function () use ($request, $borrow) {
            $daysLate = max(0, Carbon::parse($borrow->due_date)->diffInDays(now(), false));
            $condition = $request->input('condition', 'good');

            $borrow->update([
                'return_date' => now(),
                'status'      => 'returned',
            ]);

            $copyStatus = $condition === 'lost' ? 'lost' : ($condition === 'damaged' ? 'damaged' : 'available');
            $borrow->bookCopy->update(['status' => $copyStatus]);

            if ($copyStatus === 'available') {
                $borrow->bookCopy->book()->increment('available_qty');

                app(ReservationController::class)
                    ->promoteNextInQueue($borrow->bookCopy->book_id);
            }

            $createdFines = [];

            // ✅ FIX: bằng khrToUsd() — trước đây gán thẳng số Riel vào cột USD
            if ($daysLate > 0) {
                $createdFines[] = Fine::create([
                    'borrow_id' => $borrow->id,
                    'amount'    => CurrencyHelper::khrToUsd($daysLate * $this->finePerDay),
                    'reason'    => 'overdue',
                    'status'    => 'unpaid',
                ]);
            }

            //  FIX: $request->input('damage_fee') is now the RAW Riel value
            // sent by ReturnForm.vue (frontend no longer pre-converts —
            // see ReturnForm.vue below). Converted here, once, in one place.
            if ($condition === 'damaged') {
                $createdFines[] = Fine::create([
                    'borrow_id' => $borrow->id,
                    'amount'    => CurrencyHelper::khrToUsd($request->input('damage_fee', 5000)),
                    'reason'    => 'damaged',
                    'status'    => 'unpaid',
                    'notes'     => $request->input('notes'),
                ]);
            }

            if ($condition === 'lost') {
                $lostFeeKhr = $request->input('lost_fee', $borrow->bookCopy->book->price ?? 15000);
                $createdFines[] = Fine::create([
                    'borrow_id' => $borrow->id,
                    'amount'    => CurrencyHelper::khrToUsd($lostFeeKhr),
                    'reason'    => 'lost',
                    'status'    => 'unpaid',
                    'notes'     => $request->input('notes'),
                ]);
            }

            $totalFineUsd = collect($createdFines)->sum('amount');

            // FIX: notification message now shows $ — totalFine is USD
            // after conversion, not the raw Riel figure anymore.
            if ($totalFineUsd > 0) {
                Notification::create([
                    'user_id' => $borrow->member->user_id,
                    'title'   => 'You have a new fine',
                    'message' => sprintf(
                        'A fine of $%.2f was applied for "%s".',
                        $totalFineUsd,
                        $borrow->bookCopy->book->title
                    ),
                    'type'    => 'fine',
                    'link'    => '/my-fines',
                    'is_read' => false,
                    'sent_at' => now(),
                ]);
            }

            return response()->json([
                'message' => $totalFineUsd > 0
                    ? sprintf('Book returned successfully (Total fines: $%.2f).', $totalFineUsd)
                    : 'Book returned successfully.',
                'data' => $borrow->fresh()->load(['member.user', 'bookCopy.book.category', 'fines']),
            ]);
        });
    }
}