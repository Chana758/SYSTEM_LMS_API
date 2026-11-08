<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BorrowTransaction;
use App\Models\Fine;
use App\Models\Member;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Return summary stats (books, members, borrows, fines) and the low-stock list. */
    public function index(Request $request)
    {
        return response()->json([
            'total_books'        => Book::count(),
            'available_books'    => Book::sum('available_qty'),
            'total_members'      => Member::where('status', 'active')->count(),
            'borrowed_today'     => BorrowTransaction::whereDate('borrow_date', now()->toDateString())->count(),
            'currently_borrowed' => BorrowTransaction::where('status', 'borrowed')->count(),
            'overdue_count'      => BorrowTransaction::where('status', 'borrowed')
                ->where('due_date', '<', now()->toDateString())
                ->count(),
            'unpaid_fines_total' => (float) Fine::where('status', 'unpaid')->sum('amount'),
            'low_stock_books'    => Book::select('id', 'title', 'available_qty', 'total_qty')
                ->whereColumn('available_qty', '<=', DB::raw('total_qty * 0.2'))
                ->where('total_qty', '>', 0)
                ->orderBy('available_qty')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Return a merged, newest-first feed of borrows, returns, reservations, fines and new members.
     *
     * Each item carries structured fields (actor, book, amount) so the frontend can build the
     * sentence in the user's language. `description` is kept as an English fallback.
     */
    public function recentActivities(Request $request)
    {
        $limit = min(max((int) $request->get('limit', 10), 1), 50);

        $borrows = BorrowTransaction::with(['member.user:id,name', 'bookCopy.book:id,title'])
            ->latest('borrow_date')
            ->limit($limit)
            ->get()
            ->map(fn ($b) => $this->activity(
                'borrow-' . $b->id,
                'borrow',
                $b->member?->user?->name,
                $b->bookCopy?->book?->title,
                null,
                $b->created_at,
                ($b->member?->user?->name ?? 'A member') . ' borrowed "' . ($b->bookCopy?->book?->title ?? 'a book') . '"',
            ));

        $returns = BorrowTransaction::with(['member.user:id,name', 'bookCopy.book:id,title'])
            ->whereNotNull('return_date')
            ->latest('return_date')
            ->limit($limit)
            ->get()
            ->map(fn ($b) => $this->activity(
                'return-' . $b->id,
                'return',
                $b->member?->user?->name,
                $b->bookCopy?->book?->title,
                null,
                $b->updated_at,
                ($b->member?->user?->name ?? 'A member') . ' returned "' . ($b->bookCopy?->book?->title ?? 'a book') . '"',
            ));

        $reservations = Reservation::with(['member.user:id,name', 'book:id,title'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($r) => $this->activity(
                'reservation-' . $r->id,
                'reservation',
                $r->member?->user?->name,
                $r->book?->title,
                null,
                $r->created_at,
                ($r->member?->user?->name ?? 'A member') . ' reserved "' . ($r->book?->title ?? 'a book') . '"',
            ));

        $fines = Fine::with(['borrow.member.user:id,name'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($f) => $this->activity(
                'fine-' . $f->id,
                'fine',
                $f->borrow?->member?->user?->name,
                null,
                (float) $f->amount,
                $f->created_at,
                'Fine of $' . number_format((float) $f->amount, 2) . ' issued to ' . ($f->borrow?->member?->user?->name ?? 'a member'),
            ));

        $newMembers = Member::with('user:id,name')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($m) => $this->activity(
                'member-' . $m->id,
                'member',
                $m->user?->name,
                null,
                null,
                $m->created_at,
                ($m->user?->name ?? 'A new member') . ' joined the library',
            ));

        $merged = collect()
            ->concat($borrows)
            ->concat($returns)
            ->concat($reservations)
            ->concat($fines)
            ->concat($newMembers)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values();

        return response()->json($merged);
    }

    /** Build one activity-feed item. */
    private function activity(
        string $id,
        string $type,
        ?string $actor,
        ?string $book,
        ?float $amount,
        $createdAt,
        string $description,
    ): array {
        return [
            'id'          => $id,
            'type'        => $type,
            'actor'       => $actor,
            'book'        => $book,
            'amount'      => $amount,
            'description' => $description,
            'created_at'  => $createdAt,
        ];
    }

    /** Return fine payments per month for the last 6 months (months with no payments show 0). */
    public function revenueChart(Request $request)
    {
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));

        // NOTE: to_char() is PostgreSQL-only. On MySQL use DATE_FORMAT(paid_at, '%Y-%m').
        $paid = Fine::where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("to_char(paid_at, 'YYYY-MM') as ym, SUM(amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $result = $months->map(function ($date) use ($paid) {
            $key = $date->format('Y-m');
            return [
                'ym'    => $key,                 // e.g. "2026-11" — lets the frontend localise the month name
                'month' => $date->format('M'),   // English fallback for older clients
                'total' => (float) ($paid[$key] ?? 0),
            ];
        });

        return response()->json($result->values());
    }
}