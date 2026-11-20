<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BorrowTransaction;
use App\Models\Fine;
use App\Models\Member;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportExportService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    /** Reservation statuses that mean "still waiting for a book". Adjust to your schema. */
    private const RESERVATION_OPEN_STATUSES = ['pending'];

    /** Inject the service that builds report export files. */
    public function __construct(private readonly ReportExportService $exporter) {}

    /* ------------------------------------------------------------------ */
    /*  DASHBOARD                                                          */
    /* ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------ */
    /*  BORROW                                                             */
    /* ------------------------------------------------------------------ */

    /** Return the borrow report: summary cards, daily trend and a paginated list. */
    public function borrow(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            // Summary ignores the status filter so the 4 cards stay stable
            // while the table below is filtered.
            $range = BorrowTransaction::whereBetween('borrow_date', [$from, $to]);
            $base = $this->applyBorrowStatus(clone $range, $request->status);

            $summary = [
                'total'    => (clone $range)->count(),
                'returned' => (clone $range)->where('status', 'returned')->count(),
                'active'   => (clone $range)->where('status', 'borrowed')->count(),
                'overdue'  => (clone $range)->where('status', 'borrowed')->where('due_date', '<', now())->count(),
            ];

            $trend = $this->series(
                (clone $range)->selectRaw('DATE(borrow_date) as date, COUNT(*) as total')->groupBy('date')->get(),
                $from, $to
            );

            $rows = (clone $base)
                ->with(['member.user:id,name,email', 'bookCopy.book:id,title,author'])
                ->latest('borrow_date')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'status' => 'success', 'range' => $this->rangePayload($from, $to),
                'summary' => $summary, 'trend' => $trend, 'data' => $rows,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@borrow: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  FINE                                                               */
    /* ------------------------------------------------------------------ */

    /** Return the fine report: totals by status, top debtors and a paginated list. */
    public function fine(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $range = Fine::whereBetween('created_at', [$from, $to]);
            $base = (clone $range)->when($request->status, fn ($q) => $q->where('status', $request->status));

            $summary = [
                'total_amount'  => (float) (clone $range)->sum('amount'),
                'paid_amount'   => (float) (clone $range)->where('status', 'paid')->sum('amount'),
                'unpaid_amount' => (float) (clone $range)->where('status', 'unpaid')->sum('amount'),
                'waived_amount' => (float) (clone $range)->where('status', 'waived')->sum('amount'),
                'count'         => (clone $range)->count(),
            ];

            $topDebtors = $this->safe(fn () => Fine::where('status', 'unpaid')
                ->with('borrow.member.user:id,name,email')->get()
                ->groupBy(fn ($f) => $f->borrow?->member?->id ?? 0)
                ->map(function ($g) {
                    $u = $g->first()->borrow?->member?->user;
                    return [
                        'id' => $g->first()->borrow?->member?->id, 'name' => $u?->name ?? '—',
                        'email' => $u?->email, 'amount' => (float) $g->sum('amount'), 'count' => $g->count(),
                    ];
                })->sortByDesc('amount')->take(5)->values()->all(), []);

            $rows = (clone $base)
                ->with(['borrow.member.user:id,name,email', 'borrow.bookCopy.book:id,title'])
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'status' => 'success', 'range' => $this->rangePayload($from, $to),
                'summary' => $summary, 'top_debtors' => $topDebtors, 'data' => $rows,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@fine: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  USER                                                               */
    /* ------------------------------------------------------------------ */

    /** Return the user report: member activity, top borrowers, sign-up trend and a paginated list. */
    public function user(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $query = Member::with(['user:id,name,email,role_id', 'user.role:id,name'])
                ->withCount([
                    'borrows as total_borrows'  => fn ($q) => $q->whereBetween('borrow_date', [$from, $to]),
                    'borrows as active_borrows' => fn ($q) => $q->where('status', 'borrowed'),
                ])
                ->withSum(['fines as total_fines' => fn ($q) => $q->whereBetween('fines.created_at', [$from, $to])], 'amount')
                ->when($request->search, fn ($q) => $q->whereHas('user', function ($qq) use ($request) {
                    $qq->where('name', 'ilike', "%{$request->search}%")
                       ->orWhere('email', 'ilike', "%{$request->search}%");
                }))
                ->when($request->role, fn ($q) => $q->whereHas('user.role', fn ($qq) => $qq->where('name', $request->role)))
                ->orderByDesc('total_borrows');

            $topBorrowers = $this->safe(fn () => (clone $query)->limit(5)->get()
                ->filter(fn ($m) => $m->total_borrows > 0)
                ->map(fn ($m) => [
                    'id' => $m->id, 'name' => $m->user?->name ?? '—',
                    'email' => $m->user?->email, 'borrow_count' => (int) $m->total_borrows,
                ])->values()->all(), []);

            $rows = $query->paginate($request->per_page ?? 15);

            $summary = [
                'total_users' => User::count(),
                'new_users'   => User::whereBetween('created_at', [$from, $to])->count(),
                'members'     => Member::count(),
                'staff'       => User::whereHas('role', fn ($q) => $q->whereIn('name', ['admin', 'librarian']))->count(),
                'inactive'    => $this->safe(fn () => Member::whereDoesntHave(
                    'borrows', fn ($q) => $q->where('borrow_date', '>=', now()->subDays(30))
                )->count(), 0),
            ];

            $trend = $this->series(
                Member::whereBetween('created_at', [$from, $to])
                    ->selectRaw('DATE(created_at) as date, COUNT(*) as total')->groupBy('date')->get(),
                $from, $to
            );

            return response()->json([
                'status' => 'success', 'range' => $this->rangePayload($from, $to),
                'summary' => $summary, 'trend' => $trend,
                'top_borrowers' => $topBorrowers, 'data' => $rows,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@user: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  REVENUE (admin only)                                               */
    /* ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------ */
    /*  STOCK                                                              */
    /* ------------------------------------------------------------------ */

    /** Return the stock report: copy counts, low/dead stock, per-category totals and a paginated list. */
    public function stock(Request $request)
    {
        try {
            $lowStockSql = '(select count(*) from book_copies where book_copies.book_id = books.id and book_copies.status = ? and book_copies.deleted_at is null) <= 2';

            $rows = Book::with('category:id,name')
                ->withCount([
                    'bookCopies as total_copies',
                    'bookCopies as available_copies' => fn ($q) => $q->where('status', 'available'),
                    'bookCopies as borrowed_copies'  => fn ($q) => $q->where('status', 'borrowed'),
                    'bookCopies as lost_copies'      => fn ($q) => $q->where('status', 'lost'),
                ])
                ->when($request->search, fn ($q) => $q->where('title', 'ilike', "%{$request->search}%"))
                ->when($request->status, fn ($q) => $q->where('category_id', $request->status))
                ->when($request->boolean('low_stock'), fn ($q) => $q->whereRaw($lowStockSql, ['available']))
                ->when($request->boolean('dead_stock'), fn ($q) => $q->whereDoesntHave('borrows'))
                ->orderBy('title')
                ->paginate($request->per_page ?? 15);

            $summary = [
                'total_books' => Book::count(),
                'available'   => BookCopy::where('status', 'available')->count(),
                'borrowed'    => BookCopy::where('status', 'borrowed')->count(),
                'low_stock'   => Book::whereRaw($lowStockSql, ['available'])->count(),
                'dead_stock'  => $this->safe(fn () => Book::whereDoesntHave('borrows')->count(), 0),
                // old names kept for backward compatibility
                'total_copies'     => BookCopy::count(),
                'available_copies' => BookCopy::where('status', 'available')->count(),
                'borrowed_copies'  => BookCopy::where('status', 'borrowed')->count(),
                'lost_copies'      => BookCopy::where('status', 'lost')->count(),
            ];

            $byCategory = $this->safe(fn () => Book::with('category:id,name')
                ->withCount([
                    'bookCopies as available_copies' => fn ($q) => $q->where('status', 'available'),
                    'bookCopies as borrowed_copies'  => fn ($q) => $q->where('status', 'borrowed'),
                    'bookCopies as lost_copies'      => fn ($q) => $q->where('status', 'lost'),
                ])->get()
                ->groupBy(fn ($b) => $b->category?->name ?? '—')
                ->map(fn ($g, $name) => [
                    'name' => $name,
                    'available' => (int) $g->sum('available_copies'),
                    'borrowed'  => (int) $g->sum('borrowed_copies'),
                    'lost'      => (int) $g->sum('lost_copies'),
                ])->values()->all(), []);

            return response()->json([
                'status' => 'success', 'summary' => $summary, 'by_category' => $byCategory, 'data' => $rows,
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@stock: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  EXPORT / HISTORY / DELETE                                          */
    /* ------------------------------------------------------------------ */

    /** Generate a report file (pdf/excel/csv), log it in report history and return the download link (admin only). */
    public function export(Request $request)
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'type'      => 'required|in:borrow,fine,user,revenue,stock',
            'format'    => 'required|in:pdf,excel,csv',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
            'status'    => 'nullable|string',
            'all_time'  => 'nullable|boolean',
        ]);

        $report = Report::create([
            'type'         => $validated['type'],
            'generated_by' => $request->user()->id,
            'date_from'    => $validated['date_from'] ?? null,
            'date_to'      => $validated['date_to'] ?? null,
            'format'       => $validated['format'],
            'status'       => Report::STATUS_PENDING,
        ]);

        try {
            [$from, $to] = $this->resolveRange($request);
            [$rows, $headings] = $this->buildExportRows($validated['type'], $from, $to, $validated['status'] ?? null);

            $path = $this->exporter->export(
                $validated['type'], $validated['format'], $rows, $headings, now()->format('Y-m-d_His')
            );

            $report->update(['file_url' => $path, 'status' => Report::STATUS_COMPLETED]);

            return response()->json([
                'status'       => 'success',
                'message'      => 'Report generated.',
                'data'         => $report->fresh(),
                'download_url' => asset('storage/' . $path),
            ], 201);

        } catch (\Throwable $th) {
            $report->update(['status' => Report::STATUS_FAILED]);
            Log::error('ReportController@export: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => 'Failed to generate report: ' . $th->getMessage()], 500);
        }
    }

    /** List previously generated reports (report history), optionally filtered by type. */
    public function index(Request $request)
    {
        try {
            $reports = Report::with('generatedBy:id,name')
                ->when($request->type, fn ($q) => $q->where('type', $request->type))
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'data' => $reports], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** Delete a report record and its stored file (admin only). */
    public function destroy(Request $request, $id)
    {
        $this->authorizeAdmin($request);

        try {
            $report = Report::findOrFail($id);

            if ($report->file_url) {
                Storage::disk('public')->delete($report->file_url);
            }
            $report->delete();

            return response()->json(['status' => 'success', 'message' => 'Report deleted.'], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HELPERS                                                            */
    /* ------------------------------------------------------------------ */

    /** Abort with 403 unless the logged-in user is an admin. */
    private function authorizeAdmin(Request $request): void
    {
        $role = $request->user()->loadMissing('role')->role?->name;

        if ($role !== 'admin') {
            abort(403, 'Only admins can access this report.');
        }
    }

    /** Resolve the date range: all_time = oldest record until now, no dates = last 30 days. */
    private function resolveRange(Request $request): array
    {
        if ($request->boolean('all_time')) {
            $oldest = collect([
                BorrowTransaction::min('borrow_date'),
                Member::min('created_at'),
                Fine::min('created_at'),
            ])->filter()->map(fn ($d) => Carbon::parse($d))->min();

            $from = $oldest ? $oldest->copy()->startOfDay() : now()->subDays(30)->startOfDay();
            return [$from, now()->endOfDay()];
        }

        $from = $request->date_from ? Carbon::parse($request->date_from)->startOfDay() : now()->subDays(30)->startOfDay();
        $to   = $request->date_to   ? Carbon::parse($request->date_to)->endOfDay()     : now()->endOfDay();

        return [$from, $to];
    }

    /** The resolved date range as Y-m-d strings, returned so the UI can show the real period. */
    private function rangePayload(Carbon $from, Carbon $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /** Runs an optional block; a failure here must never break the whole page. */
    private function safe(callable $fn, $default = null)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('ReportController optional block failed: ' . $e->getMessage());
            return $default;
        }
    }

    /** Sparse DB rows (date,total) -> full series. Daily, or monthly when range > 92 days. */
    private function series($sparse, Carbon $from, Carbon $to): array
    {
        $monthly = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > 92;

        $keys = [];
        if ($monthly) {
            for ($d = $from->copy()->startOfMonth(); $d <= $to; $d->addMonth()) {
                $keys[] = $d->format('Y-m');
            }
        } else {
            foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
                $keys[] = $day->toDateString();
            }
        }

        $sums = array_fill_keys($keys, 0.0);
        foreach ($sparse as $row) {
            $k = Carbon::parse($row->date)->format($monthly ? 'Y-m' : 'Y-m-d');
            if (isset($sums[$k])) {
                $sums[$k] += (float) $row->total;
            }
        }

        return array_map(fn ($k) => ['date' => $k, 'total' => $sums[$k]], $keys);
    }

    /** Filter borrows by status, where "overdue" means still borrowed and past the due date. */
    private function applyBorrowStatus($query, ?string $status)
    {
        if (!$status) {
            return $query;
        }
        if ($status === 'overdue') {
            return $query->where('status', 'borrowed')->where('due_date', '<', now());
        }
        return $query->where('status', $status);
    }

    /** Build the rows and column headings for each export type. */
    private function buildExportRows(string $type, Carbon $from, Carbon $to, ?string $status): array
    {
        return match ($type) {
            'borrow' => [
                $this->applyBorrowStatus(
                    BorrowTransaction::with(['member.user:id,name', 'bookCopy.book:id,title'])
                        ->whereBetween('borrow_date', [$from, $to]),
                    $status
                )->get()->map(fn ($b) => [
                    $b->id, $b->member?->user?->name, $b->bookCopy?->book?->title,
                    $b->borrow_date, $b->due_date, $b->return_date ?? '—', $b->status,
                ]),
                ['ID', 'Member', 'Book', 'Borrowed', 'Due', 'Returned', 'Status'],
            ],
            'fine' => [
                Fine::with(['borrow.member.user:id,name'])
                    ->whereBetween('created_at', [$from, $to])
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->get()
                    ->map(fn ($f) => [$f->id, $f->borrow?->member?->user?->name, $f->amount, $f->status, $f->paid_at ?? '—']),
                ['ID', 'Member', 'Amount', 'Status', 'Paid At'],
            ],
            'user' => [
                Member::with('user:id,name,email')
                    ->withCount(['borrows as total_borrows' => fn ($q) => $q->whereBetween('borrow_date', [$from, $to])])
                    ->get()
                    ->map(fn ($m) => [$m->user?->id, $m->user?->name, $m->user?->email, 'member', $m->total_borrows]),
                ['ID', 'Name', 'Email', 'Role', 'Borrows (period)'],
            ],
            'stock' => [
                Book::with('category:id,name')
                    ->withCount([
                        'bookCopies as total_copies',
                        'bookCopies as available_copies' => fn ($q) => $q->where('status', 'available'),
                        'bookCopies as borrowed_copies'  => fn ($q) => $q->where('status', 'borrowed'),
                    ])
                    ->when($status, fn ($q) => $q->where('category_id', $status))
                    ->get()
                    ->map(fn ($b) => [
                        $b->id, $b->title, $b->author, $b->category?->name ?? '—',
                        $b->total_copies, $b->available_copies, $b->borrowed_copies,
                    ]),
                ['ID', 'Title', 'Author', 'Category', 'Copies', 'Available', 'Borrowed'],
            ],
            'revenue' => [
                Fine::where('status', 'paid')
                    ->whereBetween('paid_at', [$from, $to])
                    ->with('borrow.member.user:id,name')
                    ->get()
                    ->map(fn ($f) => [$f->id, $f->borrow?->member?->user?->name, $f->amount, $f->paid_at]),
                ['Fine ID', 'Member', 'Amount', 'Paid At'],
            ],
            default => [collect(), []],
        };
    }
}