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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function __construct(private readonly ReportExportService $exporter) {}

    public function dashboard(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $data = [
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'kpis'  => [
                    'total_borrows'    => BorrowTransaction::whereBetween('borrow_date', [$from, $to])->count(),
                    'active_borrows'   => BorrowTransaction::where('status', 'borrowed')->count(),
                    'overdue_borrows'  => BorrowTransaction::where('status', 'borrowed')->where('due_date', '<', now())->count(),
                    'total_fines'      => (float) Fine::whereBetween('created_at', [$from, $to])->sum('amount'),
                    'unpaid_fines'     => (float) Fine::where('status', 'unpaid')->sum('amount'),
                    'new_members'      => Member::whereBetween('created_at', [$from, $to])->count(),
                    'total_books'      => Book::count(),
                    'available_copies' => BookCopy::where('status', 'available')->count(),
                ],
                'borrow_trend' => BorrowTransaction::whereBetween('borrow_date', [$from, $to])
                    ->selectRaw('DATE(borrow_date) as date, COUNT(*) as total')
                    ->groupBy('date')->orderBy('date')->get(),
                'revenue_trend' => Fine::where('status', 'paid')
                    ->whereBetween('paid_at', [$from, $to])
                    ->selectRaw('DATE(paid_at) as date, SUM(amount) as total')
                    ->groupBy('date')->orderBy('date')->get(),
                'top_books' => Book::withCount(['borrows as borrow_count' => function ($q) use ($from, $to) {
                        $q->whereBetween('borrow_date', [$from, $to]);
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

    public function borrow(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $base = BorrowTransaction::whereBetween('borrow_date', [$from, $to])
                ->when($request->status, fn ($q) => $q->where('status', $request->status));

            $summary = [
                'total'    => (clone $base)->count(),
                'returned' => (clone $base)->where('status', 'returned')->count(),
                'active'   => (clone $base)->where('status', 'borrowed')->count(),
                'overdue'  => (clone $base)->where('status', 'borrowed')->where('due_date', '<', now())->count(),
            ];

            $rows = (clone $base)
                ->with(['member.user:id,name,email', 'bookCopy.book:id,title,author'])
                ->latest('borrow_date')
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'summary' => $summary, 'data' => $rows], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@borrow: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function fine(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $base = Fine::whereBetween('created_at', [$from, $to])
                ->when($request->status, fn ($q) => $q->where('status', $request->status));

            $summary = [
                'total_amount'  => (float) (clone $base)->sum('amount'),
                'paid_amount'   => (float) (clone $base)->where('status', 'paid')->sum('amount'),
                'unpaid_amount' => (float) (clone $base)->where('status', 'unpaid')->sum('amount'),
                'count'         => (clone $base)->count(),
            ];

            $rows = (clone $base)
                ->with(['borrow.member.user:id,name,email', 'borrow.bookCopy.book:id,title'])
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'summary' => $summary, 'data' => $rows], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@fine: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    public function user(Request $request)
    {
        try {
            [$from, $to] = $this->resolveRange($request);

            $rows = Member::with(['user:id,name,email,role_id', 'user.role:id,name'])
                ->withCount([
                    'borrows as total_borrows'  => fn ($q) => $q->whereBetween('borrow_date', [$from, $to]),
                    'borrows as active_borrows' => fn ($q) => $q->where('status', 'borrowed'),
                ])
                ->withSum(['fines as total_fines' => function ($q) use ($from, $to) {
                    $q->whereBetween('fines.created_at', [$from, $to]);
                }], 'amount')
                ->when($request->search, fn ($q) => $q->whereHas('user', function ($qq) use ($request) {
                    $qq->where('name', 'like', "%{$request->search}%")
                       ->orWhere('email', 'like', "%{$request->search}%");
                }))
                ->orderByDesc('total_borrows')
                ->paginate($request->per_page ?? 15);

            $summary = [
                'total_users' => User::count(),
                'new_users'   => User::whereBetween('created_at', [$from, $to])->count(),
                'members'     => Member::count(),
                'staff'       => User::whereHas('role', fn ($q) => $q->whereIn('name', ['admin', 'librarian']))->count(),
            ];

            return response()->json(['status' => 'success', 'summary' => $summary, 'data' => $rows], 200);

        } catch (\Throwable $th) {
            Log::error('ReportController@user: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

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

    public function stock(Request $request)
    {
        try {
            $rows = Book::withCount([
                    'bookCopies as total_copies',
                    'bookCopies as available_copies' => fn ($q) => $q->where('status', 'available'),
                    'bookCopies as borrowed_copies'   => fn ($q) => $q->where('status', 'borrowed'),
                    'bookCopies as lost_copies'       => fn ($q) => $q->where('status', 'lost'),
                ])
                ->when($request->search, fn ($q) => $q->where('title', 'like', "%{$request->search}%"))
                ->when($request->boolean('low_stock'), fn ($q) => $q->having('available_copies', '<=', 2))
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
                BorrowTransaction::with(['member.user:id,name', 'bookCopy.book:id,title'])
                    ->whereBetween('borrow_date', [$from, $to])
                    ->when($status, fn ($q) => $q->where('status', $status))
                    ->get()
                    ->map(fn ($b) => [
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
                    ->withCount('borrows')
                    ->get()
                    ->map(fn ($m) => [$m->user?->id, $m->user?->name, $m->user?->email, 'member', $m->borrows_count]),
                ['ID', 'Name', 'Email', 'Role', 'Total Borrows'],
            ],
            'stock' => [
                Book::withCount('bookCopies')->get()
                    ->map(fn ($b) => [$b->id, $b->title, $b->author, $b->book_copies_count]),
                ['ID', 'Title', 'Author', 'Copies'],
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
