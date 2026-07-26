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
    /**
     * GET /dashboard
     * Accessible by: admin, librarian
     * Returns the summary stat cards + circulation overview + low-stock
     * list shown at the top of DashboardPage.vue.
     */
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
     * GET /dashboard/recent-activities
     * Accessible by: admin, librarian
     *
     * Pulls the last N events across borrows, returns, reservations,
     * fines, and new member sign-ups, merges them into one feed sorted
     * by recency, and normalizes each into the
     * { id, type, description, created_at } shape RecentActivities.vue
     * expects.
     */
    public function recentActivities(Request $request)
    {
        $limit = (int) $request->get('limit', 10);

        $borrows = BorrowTransaction::with(['member.user:id,name', 'bookCopy.book:id,title'])
            ->latest('borrow_date')
            ->limit($limit)
            ->get()
            ->map(fn ($b) => [
                'id' => 'borrow-' . $b->id,
                'type' => 'borrow',
                'description' => ($b->member?->user?->name ?? 'A member') . ' borrowed "' . ($b->bookCopy?->book?->title ?? 'a book') . '"',
                'created_at' => $b->created_at,
            ]);

        $returns = BorrowTransaction::with(['member.user:id,name', 'bookCopy.book:id,title'])
            ->whereNotNull('return_date')
            ->latest('return_date')
            ->limit($limit)
            ->get()
            ->map(fn ($b) => [
                'id' => 'return-' . $b->id,
                'type' => 'return',
                'description' => ($b->member?->user?->name ?? 'A member') . ' returned "' . ($b->bookCopy?->book?->title ?? 'a book') . '"',
                'created_at' => $b->updated_at,
            ]);

        $reservations = Reservation::with(['member.user:id,name', 'book:id,title'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => 'reservation-' . $r->id,
                'type' => 'reservation',
                'description' => ($r->member?->user?->name ?? 'A member') . ' reserved "' . ($r->book?->title ?? 'a book') . '"',
                'created_at' => $r->created_at,
            ]);

        $fines = Fine::with(['borrow.member.user:id,name'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($f) => [
                'id' => 'fine-' . $f->id,
                'type' => 'fine',
                'description' => 'Fine of $' . number_format($f->amount, 2) . ' issued to ' . ($f->borrow?->member?->user?->name ?? 'a member'),
                'created_at' => $f->created_at,
            ]);

        $newMembers = Member::with('user:id,name')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($m) => [
                'id' => 'member-' . $m->id,
                'type' => 'member',
                'description' => ($m->user?->name ?? 'A new member') . ' joined the library',
                'created_at' => $m->created_at,
            ]);

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

    /**
     * GET /dashboard/revenue-chart
     * Accessible by: admin, librarian
     *
     * Returns fine payments collected, grouped by month, for the last
     * 6 months (including months with $0 so the x-axis stays
     * contiguous), in the { month: 'Jan', total: 123.45 } shape
     * RevenueChart.vue expects.
     *
     * NOTE: to_char() is PostgreSQL syntax, matching this project's
     * pgsql connection (seen elsewhere, e.g. ScanController's use of
     * ilike). If this project ever moves to MySQL, swap this for
     * DATE_FORMAT(paid_at, '%Y-%m').
     */
    public function revenueChart(Request $request)
    {
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));

        $paid = Fine::where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("to_char(paid_at, 'YYYY-MM') as ym, SUM(amount) as total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $result = $months->map(function ($date) use ($paid) {
            $key = $date->format('Y-m');
            return [
                'month' => $date->format('M'),
                'total' => (float) ($paid[$key] ?? 0),
            ];
        });

        return response()->json($result->values());
    }
}