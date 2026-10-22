<?php

namespace App\Services;

use App\Exceptions\ReservationException;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * All reservation business rules live here, shared by the controller,
 * the scheduled command and the borrow / return flow.
 *
 * Lifecycle:
 *   pending --(copy free)--> ready --(picked up / borrowed)--> fulfilled
 *   pending|ready --(member or staff)--> cancelled
 *   pending|ready --(expire_date passed)--> expired
 */
class ReservationService
{
    //  Config 

    public function pendingExpiryDays(): int
    {
        return (int) config('library.reservation.pending_expiry_days', 30);
    }

    public function pickupWindowDays(): int
    {
        return (int) config('library.reservation.pickup_window_days', 3);
    }

    public function maxActivePerMember(): int
    {
        return (int) config('library.reservation.max_active_per_member', 5);
    }

    //  Helpers 

    public function isStaff(User $user): bool
    {
        return in_array($user->role?->name, ['admin', 'librarian'], true);
    }

    /** Get the member profile of a user (404 if none). */
    public function memberFor(User $user): Member
    {
        $member = Member::where('user_id', $user->id)->first();

        if (! $member) {
            throw new ReservationException('Member profile not found for this account.', 404);
        }

        return $member;
    }

    /** Copies anyone may borrow right now (copies held for "ready" reservations are not free). */
    public function freeCopies(int $bookId): int
    {
        $available = BookCopy::where('book_id', $bookId)->where('status', 'available')->count();
        $held      = Reservation::where('book_id', $bookId)->where('status', Reservation::READY)->count();

        return max(0, $available - $held);
    }

    /** Send a reservation notification to one user. */
    protected function notify(int $userId, string $title, string $message, ?string $link = null): void
    {
        Notification::create([
            'user_id' => $userId,
            'title'   => $title,
            'message' => $message,
            'type'    => 'reservation',
            'link'    => $link,
            'is_read' => false,
            'sent_at' => now(),
        ]);
    }

    /** Send a notification to all admins and librarians (optionally except one user). */
    protected function notifyStaff(string $title, string $message, string $link, ?int $exceptUserId = null): void
    {
        $ids = User::whereHas('role', fn ($q) => $q->whereIn('name', ['admin', 'librarian']))
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        Notification::insert($ids->map(fn ($id) => [
            'user_id'    => $id,
            'title'      => $title,
            'message'    => $message,
            'type'       => 'reservation',
            'link'       => $link,
            'is_read'    => false,
            'sent_at'    => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    /** Keep priority_order as 1..n with no gaps so queue position is always correct. */
    public function resequence(int $bookId): void
    {
        $rows = Reservation::where('book_id', $bookId)->active()
            ->orderBy('priority_order')->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'priority_order']);

        foreach ($rows as $index => $row) {
            $position = $index + 1;
            if ((int) $row->priority_order !== $position) {
                Reservation::whereKey($row->id)->update(['priority_order' => $position]);
            }
        }
    }

    // Create 

    /** Add a member to a book's queue (staff may reserve on behalf of a member). */
    public function create(User $actor, int $bookId, ?int $memberId = null): Reservation
    {
        return DB::transaction(function () use ($actor, $bookId, $memberId) {
            $member = $this->isStaff($actor)
                ? Member::findOrFail($memberId)
                : $this->memberFor($actor);

            // Lock the book so two people can never both get priority 1
            Book::whereKey($bookId)->lockForUpdate()->firstOrFail();

            if (Reservation::where('book_id', $bookId)->where('member_id', $member->id)->active()->exists()) {
                throw new ReservationException('You already have an active reservation for this book.');
            }

            $limit = $this->maxActivePerMember();
            if (Reservation::where('member_id', $member->id)->active()->count() >= $limit) {
                throw new ReservationException("A member can have at most {$limit} active reservations.");
            }

            if ($this->freeCopies($bookId) > 0) {
                throw new ReservationException('This book is currently available. Please borrow it directly.');
            }

            $nextPriority = (int) Reservation::where('book_id', $bookId)->active()->max('priority_order') + 1;

            $reservation = Reservation::create([
                'book_id'        => $bookId,
                'member_id'      => $member->id,
                'reserved_date'  => now(),
                'expire_date'    => now()->addDays($this->pendingExpiryDays()),
                'status'         => Reservation::PENDING,
                'priority_order' => $nextPriority,
            ]);

            $reservation->load(['book', 'member.user']);

            $this->notify(
                $member->user_id,
                'Reservation Confirmed',
                "You have been added to the queue for \"{$reservation->book->title}\" (position {$nextPriority}).",
                '/my-reservations'
            );

            $this->notifyStaff(
                'New Book Reservation',
                "Member {$reservation->member->user->name} has requested a reservation for \"{$reservation->book->title}\".",
                "/reservations/{$reservation->id}",
                $actor->id
            );

            return $reservation->load(['member.user', 'book.category']);
        });
    }

    // Cancel 

    /** Cancel a pending/ready reservation; if it was ready, promote the next in queue. */
    public function cancel(Reservation $reservation, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor) {
            // Re-read under lock so a double click can't cancel twice
            $r = Reservation::with(['book', 'member.user'])->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if (! $r->isActive()) {
                throw new ReservationException('Only pending or ready reservations can be cancelled.');
            }

            $wasReady = $r->status === Reservation::READY;
            $byStaff  = $this->isStaff($actor);

            $r->update([
                'status'       => Reservation::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            $this->notify(
                $r->member->user_id,
                'Reservation Cancelled',
                $byStaff
                    ? "Your reservation for \"{$r->book->title}\" was cancelled by a librarian."
                    : "Your reservation for \"{$r->book->title}\" has been cancelled.",
                '/my-reservations'
            );

            if (! $byStaff) {
                $this->notifyStaff(
                    'Reservation Cancelled',
                    "{$r->member->user->name} cancelled the reservation for \"{$r->book->title}\".",
                    "/reservations/{$r->id}",
                    $actor->id
                );
            }

            // The held copy is free again, so offer it to the next person
            if ($wasReady) {
                $this->promoteNextInQueue($r->book_id);
            }
            $this->resequence($r->book_id);

            return $r->load(['member.user', 'book.category']);
        });
    }

    //  Fulfill 

    /** Staff marks a ready reservation as fulfilled. */
    public function fulfill(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation) {
            $r = Reservation::with(['book', 'member.user'])->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($r->status !== Reservation::READY) {
                throw new ReservationException('Only ready reservations can be fulfilled.');
            }

            $r->update([
                'status'       => Reservation::FULFILLED,
                'fulfilled_at' => now(),
            ]);

            $this->notify(
                $r->member->user_id,
                'Reservation Completed',
                "You've picked up \"{$r->book->title}\". Enjoy your read!",
                '/my-reservations'
            );

            $this->resequence($r->book_id);

            return $r->load(['member.user', 'book.category']);
        });
    }

    //  Borrow-flow integration 

    /** Call BEFORE lending: true if a copy is free, or the member holds a ready reservation. */
    public function canBorrow(int $bookId, int $memberId): bool
    {
        if ($this->freeCopies($bookId) > 0) {
            return true;
        }

        return Reservation::where('book_id', $bookId)
            ->where('member_id', $memberId)
            ->where('status', Reservation::READY)
            ->exists();
    }

    /** Call AFTER the borrow is saved: auto-close the member's active reservation for the book. */
    public function completeForBorrow(int $bookId, int $memberId): void
    {
        DB::transaction(function () use ($bookId, $memberId) {
            $r = Reservation::where('book_id', $bookId)
                ->where('member_id', $memberId)
                ->active()
                ->lockForUpdate()
                ->first();

            if (! $r) {
                return;
            }

            $r->update([
                'status'       => Reservation::FULFILLED,
                'fulfilled_at' => now(),
            ]);

            $this->resequence($bookId);
        });
    }

    //  Queue movement 

    /**
     * Turn the next pending reservations into "ready" while free copies exist.
     * Call after a copy is returned, added or set back to 'available'.
     * Returns the number of reservations promoted.
     */
    public function promoteNextInQueue(int $bookId): int
    {
        return DB::transaction(function () use ($bookId) {
            $promoted = 0;

            while ($this->freeCopies($bookId) > 0) {
                $next = Reservation::with(['member.user', 'book'])
                    ->where('book_id', $bookId)
                    ->where('status', Reservation::PENDING)
                    ->orderBy('priority_order')->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $next) {
                    break;
                }

                $next->update([
                    'status'      => Reservation::READY,
                    'ready_at'    => now(),
                    'expire_date' => now()->addDays($this->pickupWindowDays()),
                ]);

                $this->notify(
                    $next->member->user_id,
                    'Your reserved book is ready!',
                    "\"{$next->book->title}\" is now ready for pickup. Please collect it by "
                        . $next->expire_date->format('M d, Y') . '.',
                    '/my-reservations'
                );

                $promoted++;
            }

            return $promoted;
        });
    }

    //  Scheduled expiry 

    /** Expire overdue reservations (scheduled job); returns the number expired. */
    public function expireOverdue(): int
    {
        $count = 0;

        $ids = Reservation::active()
            ->where('expire_date', '<', now()->toDateString())
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count) {
                $r = Reservation::with(['member.user', 'book'])->whereKey($id)->lockForUpdate()->first();

                // Skip if it changed while iterating
                if (! $r || ! $r->isActive() || ! $r->expire_date->lt(today())) {
                    return;
                }

                $wasReady = $r->status === Reservation::READY;

                $r->update([
                    'status'     => Reservation::EXPIRED,
                    'expired_at' => now(),
                ]);

                $this->notify(
                    $r->member->user_id,
                    'Reservation expired',
                    "Your reservation for \"{$r->book->title}\" has expired.",
                    '/my-reservations'
                );

                if ($wasReady) {
                    $this->promoteNextInQueue($r->book_id);
                }
                $this->resequence($r->book_id);

                $count++;
            });
        }

        return $count;
    }

    //  Stats 

    /** Reservation counts per status, for the dashboard cards. */
    public function stats(): array
    {
        $counts = Reservation::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = ['total' => (int) $counts->sum()];

        foreach (Reservation::STATUSES as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }
}