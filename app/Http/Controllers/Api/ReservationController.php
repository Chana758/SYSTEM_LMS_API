<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Circulation\StoreReservationRequest;
use App\Models\BookCopy;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Reservation;
use App\Models\User;
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
     */
    public function store(StoreReservationRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $user = $request->user();

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

            // Lock existing queue rows by selecting the last reservation record
            $lastReservation = Reservation::where('book_id', $bookId)
                ->whereIn('status', ['pending', 'ready'])
                ->orderByDesc('priority_order')
                ->lockForUpdate()
                ->first();

            $nextPriority = $lastReservation ? $lastReservation->priority_order : 0;

            $reservation = Reservation::create([
                'book_id'        => $bookId,
                'member_id'      => $member->id,
                'reserved_date'  => now(),
                'expire_date'    => now()->addDays($this->pendingExpiryDays),
                'status'         => 'pending',
                'priority_order' => $nextPriority + 1,
            ]);

            // Load book and member relationships for notifications
            $reservation->load(['book', 'member.user']);

            // 1. Notify the member about their confirmed reservation in the queue
            Notification::create([
                'user_id' => $member->user_id,
                'title'   => 'Reservation Confirmed',
                'message' => "You have been added to the queue for \"{$reservation->book->title}\".",
                'type'    => 'reservation',
                'link'    => "/my-reservations/{$reservation->id}",
                'is_read' => false,
                'sent_at' => now(),
            ]);

            // 2. Notify all Admin and Librarian users in real-time
            $staffUsers = User::whereHas('role', fn ($q) => $q->whereIn('name', ['admin', 'librarian']))->pluck('id');

            if ($staffUsers->isNotEmpty()) {
                $staffNotifications = $staffUsers->map(fn ($staffId) => [
                    'user_id'    => $staffId,
                    'title'      => 'New Book Reservation',
                    'message'    => "Member {$reservation->member->user->name} has requested a reservation for \"{$reservation->book->title}\".",
                    'type'       => 'reservation',
                    'link'       => "/admin/reservations/{$reservation->id}",
                    'is_read'    => false,
                    'sent_at'    => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->toArray();

                Notification::insert($staffNotifications);
            }

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
        $isStaffCancelling = in_array($user->role?->name, ['admin', 'librarian'], true);

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
        $reservation->load(['book', 'member.user']);

        // Notify the member — this matters most when staff cancel on the
        // member's behalf, since the member wouldn't otherwise know.
        // FIX: previously no notification was sent on cancel at all.
        Notification::create([
            'user_id' => $reservation->member->user_id,
            'title'   => 'Reservation Cancelled',
            'message' => $isStaffCancelling
                ? "Your reservation for \"{$reservation->book->title}\" was cancelled by a librarian."
                : "Your reservation for \"{$reservation->book->title}\" has been cancelled.",
            'type'    => 'reservation',
            'is_read' => false,
            'sent_at' => now(),
        ]);

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
        $reservation->load(['book', 'member.user']);

        // FIX: previously no notification was sent on fulfill — the member
        // had no confirmation that their pickup was recorded.
        Notification::create([
            'user_id' => $reservation->member->user_id,
            'title'   => 'Reservation Completed',
            'message' => "You've picked up \"{$reservation->book->title}\". Enjoy your read!",
            'type'    => 'reservation',
            'is_read' => false,
            'sent_at' => now(),
        ]);

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