<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Circulation\StoreReservationRequest;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReservationController extends Controller
{
    public function __construct(protected ReservationService $reservations)
    {
    }

    /** List all reservations (Admin / Librarian) with filters and pagination. */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Reservation::class);

        $query = Reservation::with(['member.user', 'book.category'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $statuses = array_intersect(explode(',', $request->status), Reservation::STATUSES);
            if ($statuses) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($request->filled('book_id')) {
            $query->where('book_id', $request->book_id);
        }

        if ($request->filled('member_id')) {
            $query->where('member_id', $request->member_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('member.user', fn ($qq) => $qq->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('book', fn ($qq) => $qq->where('title', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    /** Reservations of the logged-in member (?scope=active|history, ?status=a,b). */
    public function myReservations(Request $request)
    {
        $member = $this->reservations->memberFor($request->user());

        $query = Reservation::with(['book.category'])
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->scope === 'active') {
            $query->active();
        } elseif ($request->scope === 'history') {
            $query->history();
        }

        if ($request->filled('status')) {
            $statuses = array_intersect(explode(',', $request->status), Reservation::STATUSES);
            if ($statuses) {
                $query->whereIn('status', $statuses);
            }
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    /** Dashboard counts (independent of the current page). */
    public function stats()
    {
        Gate::authorize('viewAny', Reservation::class);

        return response()->json($this->reservations->stats());
    }

    /** Create a reservation (staff may pass member_id to reserve for a member). */
    public function store(StoreReservationRequest $request)
    {
        $reservation = $this->reservations->create(
            $request->user(),
            (int) $request->book_id,
            $request->member_id ? (int) $request->member_id : null
        );

        return response()->json([
            'message' => 'Book reserved successfully. You are in the queue.',
            'data'    => $reservation,
        ], 201);
    }

    /** Show a single reservation. */
    public function show(Reservation $reservation)
    {
        Gate::authorize('view', $reservation);

        return response()->json($reservation->load(['member.user', 'book.category']));
    }

    /** Cancel a reservation. */
    public function cancel(Request $request, Reservation $reservation)
    {
        Gate::authorize('cancel', $reservation);

        return response()->json([
            'message' => 'Reservation cancelled successfully.',
            'data'    => $this->reservations->cancel($reservation, $request->user()),
        ]);
    }

    /** Mark a reservation as fulfilled (member picked up the book). */
    public function fulfill(Reservation $reservation)
    {
        Gate::authorize('fulfill', $reservation);

        return response()->json([
            'message' => 'Reservation marked as fulfilled.',
            'data'    => $this->reservations->fulfill($reservation),
        ]);
    }

    /** Items per page: default 15, clamped to 1-100. */
    protected function perPage(Request $request): int
    {
        return min(max((int) $request->get('per_page', 15), 1), 100);
    }
}