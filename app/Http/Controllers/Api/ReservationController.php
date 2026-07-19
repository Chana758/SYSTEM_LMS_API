<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Circulation\StoreReservationRequest;
use App\Models\BookCopy;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    // Maximum time a reservation request can stay in the pending queue (in days)
    protected int $pendingExpiryDays = 30;

    // Once a book is ready, the member has this many days to pick it up
    protected int $pickupWindowDays = 3;

    /**
     * Get a list of all reservations (Admin/Librarian access).
     */
    public function index(Request $request)
    {
        $query = Reservation::with(['member.user', 'book.category'])
            ->orderBy('priority_order');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('member.user', fn ($qq) => $qq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('book', fn ($qq) => $qq->where('title', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    /**
     * Get reservations specific to the authenticated member.
     */
    public function myReservations(Request $request)
    {
        $member = Member::where('user_id', $request->user()->id)->firstOrFail();

        $reservations = Reservation::with(['book.category'])
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        return response()->json($reservations);
    }

    /**
     * Create a new reservation.
     *
     * - Admin/Librarian: must supply member_id (reserving on behalf of someone).
     * - Member: member_id is resolved from the authenticated user, ignoring
     *   whatever (if anything) was submitted in the request body. This keeps
     *   the frontend simple (member never needs to know their own member_id)
     *   and prevents a member from ever reserving as someone else.
     */
    public function store(StoreReservationRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $user = $request->user();

            // NOTE: role() is a belongsTo(Role::class) relationship on User,
            // so $user->role is a Role model instance — compare $user->role->name,
            // never $user->role directly, or this check silently always fails.
            if ($user->role?->name === 'member') {
                $member = Member::where('user_id', $user->id)->firstOrFail();
            } else {
                $member = Member::findOrFail($request->member_id);
            }

            $bookId = $request->book_id;

            // Prevent duplicate active reservations for the same book
            $isDuplicate = Reservation::where('book_id', $bookId)
                ->where('member_id', $member->id)
                ->whereIn('status', ['pending', 'ready'])
                ->exists();

            if ($isDuplicate) {
                return response()->json(['message' => 'You already have an active reservation for this book.'], 422);
            }

            // Check if there is an available copy for immediate checkout
            $hasAvailableCopy = BookCopy::where('book_id', $bookId)
                ->where('status', 'available')
                ->exists();

            if ($hasAvailableCopy) {
                return response()->json(['message' => 'This book is currently available. Please borrow it directly.'], 422);
            }

            // Lock existing queue rows for this book to prevent race conditions
            $nextPriority = Reservation::where('book_id', $bookId)
                ->whereIn('status', ['pending', 'ready'])
                ->lockForUpdate()
                ->max('priority_order');

            $reservation = Reservation::create([
                'book_id'        => $bookId,
                'member_id'      => $member->id,
                'reserved_date'  => now(),
                'expire_date'    => now()->addDays($this->pendingExpiryDays),
                'status'         => 'pending',
                'priority_order' => ($nextPriority ?? 0) + 1,
            ]);

            return response()->json([
                'message' => 'Book reserved successfully. You are in the queue.',
                'data'    => $reservation->load(['member.user', 'book.category']),
            ], 201);
        });
    }

    public function show(Reservation $reservation)
    {
        return response()->json($reservation->load(['member.user', 'book.category']));
    }

    /**
     * Cancel a reservation.
     */
    public function cancel(Request $request, Reservation $reservation)
    {
        $user = $request->user();

        // Ensure member can only cancel their own reservations
        // NOTE: same relationship gotcha as store() above — use ->role->name.
        if ($user->role?->name === 'member') {
            $member = Member::where('user_id', $user->id)->firstOrFail();
            if ($reservation->member_id !== $member->id) {
                return response()->json(['message' => 'You are not authorized to cancel this reservation.'], 403);
            }
        }

        if (!in_array($reservation->status, ['pending', 'ready'])) {
            return response()->json(['message' => 'Only pending or ready reservations can be cancelled.'], 422);
        }

        $wasReady = $reservation->status === 'ready';
        $bookId = $reservation->book_id;

        $reservation->update(['status' => 'cancelled']);

        // Promote the next person in line if the cancelled reservation was 'ready'
        if ($wasReady) {
            $this->promoteNextInQueue($bookId);
        }

        return response()->json([
            'message' => 'Reservation cancelled successfully.',
            'data'    => $reservation->fresh(),
        ]);
    }

    /**
     * Mark a 'ready' reservation as fulfilled.
     */
    public function fulfill(Reservation $reservation)
    {
        if ($reservation->status !== 'ready') {
            return response()->json(['message' => 'Only ready reservations can be fulfilled.'], 422);
        }

        $reservation->update(['status' => 'fulfilled']);

        return response()->json([
            'message' => 'Reservation marked as fulfilled.',
            'data'    => $reservation->fresh(),
        ]);
    }

    /**
     * Expire reservations past their expiration date.
     * To be called via a scheduled task (ExpireReservations command).
     */
    public function expireOverdue(): int
    {
        $expired = Reservation::with(['member.user', 'book'])
            ->whereIn('status', ['pending', 'ready'])
            ->where('expire_date', '<', now()->toDateString())
            ->get();

        foreach ($expired as $reservation) {
            $wasReady = $reservation->status === 'ready';
            $bookId = $reservation->book_id;

            $reservation->update(['status' => 'expired']);

            Notification::create([
                'user_id' => $reservation->member->user_id,
                'title'   => 'Reservation expired',
                'message' => "Your reservation for \"{$reservation->book->title}\" has expired.",
                'type'    => 'reservation',
                'is_read' => false,
                'sent_at' => now(),
            ]);

            if ($wasReady) {
                $this->promoteNextInQueue($bookId);
            }
        }

        return $expired->count();
    }

    /**
     * Move the next pending reservation in line to 'ready' status,
     * and notify the member that their book is ready for pickup.
     */
    public function promoteNextInQueue(int $bookId): void
    {
        $next = Reservation::where('book_id', $bookId)
            ->where('status', 'pending')
            ->orderBy('priority_order')
            ->lockForUpdate()
            ->first();

        if ($next) {
            $next->update([
                'status'      => 'ready',
                'expire_date' => now()->addDays($this->pickupWindowDays),
            ]);

            $next->load('member.user', 'book');

            Notification::create([
                'user_id' => $next->member->user_id,
                'title'   => 'Your reserved book is ready!',
                'message' => "\"{$next->book->title}\" is now ready for pickup. Please collect it by " . $next->expire_date->format('M d, Y') . '.',
                'type'    => 'reservation',
                'link'    => "/my-reservations/{$next->id}",
                'is_read' => false,
                'sent_at' => now(),
            ]);
        }
    }
}