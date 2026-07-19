<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\Borrow;
use App\Models\Fine;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportExportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * NOTE / ASSUMPTIONS — written without direct access to the Borrow/Fine/
 * BookCopy/Book migrations, so it assumes this commonly-used shape.
 * Rename columns below if yours differ:
 *
 *   borrows:     id, user_id, book_copy_id, borrow_date, due_date,
 *                return_date (nullable), status [borrowed|returned|overdue]
 *   fines:       id, user_id, borrow_id, amount, status [unpaid|paid],
 *                paid_at (nullable), created_at
 *   book_copies: id, book_id, status [available|borrowed|lost|damaged]
 *   books:       id, title, author, category_id
 *   users:       id, name, email, role, created_at
 *
 * Also assumes Borrow::user()/bookCopy(), Fine::user()/borrow(),
 * Book::copies(), User::borrows()/fines() relations exist.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportExportService $exporter) {}

    // ─── DASHBOARD ───────────────────────────────────────────────────────

    /** GET /api/admin/reports/dashboard?date_from=&date_to= */
    public function dashboard(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $data = [
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'kpis'  => [
                    'total_borrows'    => Borrow::whereBetween('borrow_date', [$from, $to])->count(),
                    'active_borrows'   => Borrow::where('status', 'borrowed')->count(),
                    'overdue_borrows'  => Borrow::where('status', 'borrowed')->where('due_date', '<', now())->count(),
                    'total_fines'      => (float) Fine::whereBetween('created_at', [$from, $to])->sum('amount'),
                    'unpaid_fines'     => (float) Fine::where('status', 'unpaid')->sum('amount'),
                    'new_members'      => User::where('role', 'member')->whereBetween('created_at', [$from, $to])->count(),
                    'total_books'      => Book::count(),
                    'available_copies' => BookCopy::where('status', 'available')->count(),
                ],
                'borrow_trend' => Borrow::whereBetween('borrow_date', [$from, $to])
                    ->selectRaw('DATE(borrow_date) as date, COUNT(*) as total')
                    ->groupBy('date')->orderBy('date')->get(),
                'revenue_trend' => Fine::where('status', 'paid')
                    ->whereBetween('paid_at', [$from, $to])
                    ->selectRaw('DATE(paid_at) as date, SUM(amount) as total')
                    ->groupBy('date')->orderBy('date')->get(),
                'top_books' => Book::withCount(['copies as borrow_count' => function ($q) use ($from, $to) {
                        $q->join('borrows', 'borrows.book_copy_id', '=', 'book_copies.id')
                          ->whereBetween('borrows.borrow_date', [$from, $to]);
                    }])
                    ->orderByDesc('borrow_count')
                    ->limit(5)
                    ->get(['id', 'title']),
            ];

            return response()->json(['status' => 'success', 'data' => $data], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@dashboard: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ─── BORROW REPORT ───────────────────────────────────────────────────

    /** GET /api/admin/reports/borrow?date_from=&date_to=&status=&per_page= */
    public function borrow(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $base = Borrow::whereBetween('borrow_date', [$from, $to])
                ->when($request->status, fn($q) => $q->where('status', $request->status));

            $summary = [
                'total'    => (clone $base)->count(),
                'returned' => (clone $base)->where('status', 'returned')->count(),
                'active'   => (clone $base)->where('status', 'borrowed')->count(),
                'overdue'  => (clone $base)->where('status', 'borrowed')->where('due_date', '<', now())->count(),
            ];

            $rows = (clone $base)
                ->with(['user:id,name,email', 'bookCopy.book:id,title,author'])
                ->latest('borrow_date')
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'summary' => $summary, 'data' => $rows], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@borrow: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ─── FINE REPORT ─────────────────────────────────────────────────────

    /** GET /api/admin/reports/fine?date_from=&date_to=&status=&per_page= */
    public function fine(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $base = Fine::whereBetween('created_at', [$from, $to])
                ->when($request->status, fn($q) => $q->where('status', $request->status));

            $summary = [
                'total_amount'  => (float) (clone $base)->sum('amount'),
                'paid_amount'   => (float) (clone $base)->where('status', 'paid')->sum('amount'),
                'unpaid_amount' => (float) (clone $base)->where('status', 'unpaid')->sum('amount'),
                'count'         => (clone $base)->count(),
            ];

            $rows = (clone $base)
                ->with(['user:id,name,email', 'borrow.bookCopy.book:id,title'])
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'summary' => $summary, 'data' => $rows], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@fine: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ─── USER REPORT ─────────────────────────────────────────────────────

    /** GET /api/admin/reports/user?date_from=&date_to=&role=&search=&per_page= */
    public function user(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $rows = User::withCount([
                    'borrows as total_borrows'  => fn($q) => $q->whereBetween('borrow_date', [$from, $to]),
                    'borrows as active_borrows' => fn($q) => $q->where('status', 'borrowed'),
                ])
                ->withSum(['fines as total_fines' => fn($q) => $q->whereBetween('created_at', [$from, $to])], 'amount')
                ->when($request->role, fn($q) => $q->where('role', $request->role))
                ->when($request->search, fn($q) => $q->where(function ($qq) use ($request) {
                    $qq->where('name', 'like', "%{$request->search}%")
                       ->orWhere('email', 'like', "%{$request->search}%");
                }))
                ->orderByDesc('total_borrows')
                ->paginate($request->per_page ?? 15);

            $summary = [
                'total_users' => User::count(),
                'new_users'   => User::whereBetween('created_at', [$from, $to])->count(),
                'members'     => User::where('role', 'member')->count(),
                'staff'       => User::whereIn('role', ['admin', 'librarian'])->count(),
            ];

            return response()->json(['status' => 'success', 'summary' => $summary, 'data' => $rows], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@user: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ─── REVENUE REPORT ──────────────────────────────────────────────────

    /** GET /api/admin/reports/revenue?date_from=&date_to= */
    public function revenue(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $trend = Fine::where('status', 'paid')
                ->whereBetween('paid_at', [$from, $to])
                ->selectRaw('DATE(paid_at) as date, SUM(amount) as total')
                ->groupBy('date')->orderBy('date')->get();

            $summary = [
                'total_revenue' => (float) Fine::where('status', 'paid')->whereBetween('paid_at', [$from, $to])->sum('amount'),
                'outstanding'   => (float) Fine::where('status', 'unpaid')->sum('amount'),
                'avg_fine'      => round((float) (Fine::where('status', 'paid')->whereBetween('paid_at', [$from, $to])->avg('amount') ?? 0), 2),
            ];

            return response()->json(['status' => 'success', 'summary' => $summary, 'trend' => $trend], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@revenue: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ─── STOCK REPORT ────────────────────────────────────────────────────

    /** GET /api/admin/reports/stock?search=&low_stock=&per_page= */
    public function stock(Request $request)
    {
        try {
            $rows = Book::withCount([
                    'copies as total_copies',
                    'copies as available_copies' => fn($q) => $q->where('status', 'available'),
                    'copies as borrowed_copies'   => fn($q) => $q->where('status', 'borrowed'),
                    'copies as lost_copies'       => fn($q) => $q->where('status', 'lost'),
                ])
                ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
                ->when($request->boolean('low_stock'), fn($q) => $q->having('available_copies', '<=', 2))
                ->orderBy('title')
                ->paginate($request->per_page ?? 15);

            $summary = [
                'total_books'      => Book::count(),
                'total_copies'     => BookCopy::count(),
                'available_copies' => BookCopy::where('status', 'available')->count(),
                'borrowed_copies'  => BookCopy::where('status', 'borrowed')->count(),
                'lost_copies'      => BookCopy::where('status', 'lost')->count(),
            ];

            return response()->json(['status' => 'success', 'summary' => $summary, 'data' => $rows], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@stock: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // ─── EXPORT / GENERATE FILE ──────────────────────────────────────────

    /**
     * POST /api/admin/reports/export
     * body: { type, format, date_from?, date_to?, status? }
     *
     * Generates the file synchronously and stores a `reports` row.
     * For very large datasets, move the try{} body into a queued Job and
     * flip status to 'pending' immediately — the schema already supports
     * that (status: pending|completed|failed).
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'type'      => 'required|in:borrow,fine,user,revenue,stock',
            'format'    => 'required|in:pdf,excel,csv',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
            'status'    => 'nullable|string',
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
                $validated['type'],
                $validated['format'],
                $rows,
                $headings,
                now()->format('Y-m-d_His')
            );

            $report->update([
                'file_url' => $path,
                'status'   => Report::STATUS_COMPLETED,
            ]);

            return response()->json([
                'status'       => 'success',
                'message'      => 'Report generated.',
                'data'         => $report->fresh(),
                'download_url' => asset('storage/' . $path),
            ], 201);

        } catch (\Throwable $th) {
            $report->update(['status' => Report::STATUS_FAILED]);
            Log::error('ReportController@export: ' . $th->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to generate report: ' . $th->getMessage(),
            ], 500);
        }
    }

    /** GET /api/admin/reports — history of previously generated files */
    public function index(Request $request)
    {
        try {
            $reports = Report::with('generatedBy:id,name')
                ->when($request->type, fn($q) => $q->where('type', $request->type))
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'data' => $reports], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /** DELETE /api/admin/reports/{id} — removes the DB row and the file */
    public function destroy($id)
    {
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

    // ─── PRIVATE HELPERS ─────────────────────────────────────────────────

    private function resolveRange(Request $request): array
    {
        $from = $request->date_from ? Carbon::parse($request->date_from)->startOfDay() : now()->subDays(30)->startOfDay();
        $to   = $request->date_to   ? Carbon::parse($request->date_to)->endOfDay()     : now()->endOfDay();

        return [$from, $to];
    }

    private function buildExportRows(string $type, Carbon $from, Carbon $to, ?string $status): array
    {
        return match ($type) {
            'borrow' => [
                Borrow::with(['user:id,name', 'bookCopy.book:id,title'])
                    ->whereBetween('borrow_date', [$from, $to])
                    ->when($status, fn($q) => $q->where('status', $status))
                    ->get()
                    ->map(fn($b) => [
                        $b->id, $b->user?->name, $b->bookCopy?->book?->title,
                        $b->borrow_date, $b->due_date, $b->return_date ?? '—', $b->status,
                    ]),
                ['ID', 'Member', 'Book', 'Borrowed', 'Due', 'Returned', 'Status'],
            ],
            'fine' => [
                Fine::with(['user:id,name'])
                    ->whereBetween('created_at', [$from, $to])
                    ->when($status, fn($q) => $q->where('status', $status))
                    ->get()
                    ->map(fn($f) => [$f->id, $f->user?->name, $f->amount, $f->status, $f->paid_at ?? '—']),
                ['ID', 'Member', 'Amount', 'Status', 'Paid At'],
            ],
            'user' => [
                User::withCount('borrows')
                    ->when($status, fn($q) => $q->where('role', $status))
                    ->get()
                    ->map(fn($u) => [$u->id, $u->name, $u->email, $u->role, $u->borrows_count]),
                ['ID', 'Name', 'Email', 'Role', 'Total Borrows'],
            ],
            'stock' => [
                Book::withCount('copies')->get()
                    ->map(fn($b) => [$b->id, $b->title, $b->author, $b->copies_count]),
                ['ID', 'Title', 'Author', 'Copies'],
            ],
            'revenue' => [
                Fine::where('status', 'paid')
                    ->whereBetween('paid_at', [$from, $to])
                    ->get()
                    ->map(fn($f) => [$f->id, $f->user_id, $f->amount, $f->paid_at]),
                ['Fine ID', 'User ID', 'Amount', 'Paid At'],
            ],
            default => [collect(), []],
        };
    }
}