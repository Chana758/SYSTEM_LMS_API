<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\BorrowTransaction;
use App\Models\ScanHistory;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    /** Loan statuses that mean "the copy is currently out". */
    private const ACTIVE_LOAN_STATUSES = ['borrowed', 'overdue'];

    /** POST /scan - process a scanned barcode and log the attempt. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string'],
            'scan_type' => ['required', 'in:borrow,return,lookup'],
            'device' => ['nullable', 'string'],
        ]);

        $bookCopy = BookCopy::with('book:id,title,author,cover_image')
            ->where('barcode', $validated['barcode'])
            ->first();

        $activeBorrow = null;
        $bookStats = null;
        $result = 'not_found';
        $code = null;

        if ($bookCopy) {
            $result = 'success';

            // Needed by all scan types (lookup banner, return, reservation flow)
            $activeBorrow = BorrowTransaction::with('member.user:id,name')
                ->where('book_copy_id', $bookCopy->id)
                ->whereIn('status', self::ACTIVE_LOAN_STATUSES)
                ->latest('borrow_date')
                ->first();

            // Return scan with nothing borrowed is an error
            if ($validated['scan_type'] === 'return' && !$activeBorrow) {
                $result = 'error';
                $code = 'not_borrowed';
            }

            // Unavailable copy with no active loan (e.g. damaged/lost) is an error;
            // if it is checked out, stay 'success' so the reservation flow works
            if ($validated['scan_type'] === 'borrow'
                && $bookCopy->status !== 'available'
                && !$activeBorrow) {
                $result = 'error';
                $code = 'copy_unavailable';
            }

            // Stock of the whole title, used by the borrow / reserve decision in the UI
            $totalCopies = BookCopy::where('book_id', $bookCopy->book_id)->count();
            $availableCopies = BookCopy::where('book_id', $bookCopy->book_id)
                ->where('status', 'available')
                ->count();

            $bookStats = [
                'total_copies' => $totalCopies,
                'available_copies' => $availableCopies,
                'can_borrow_other' => $availableCopies > 0,
            ];
        }

        $log = ScanHistory::create([
            'user_id' => $request->user()->id,
            'book_copy_id' => $bookCopy?->id,
            'barcode_scanned' => $validated['barcode'],
            'scan_type' => $validated['scan_type'],
            'scan_result' => $result,
            'device' => $validated['device'] ?? null,
        ]);

        return response()->json([
            'result' => $result,
            'code' => $code,
            'message' => $this->messageFor($code),
            'book_copy' => $bookCopy,
            'active_borrow' => $activeBorrow,
            'book_stats' => $bookStats,
            'log' => $log,
        ], $bookCopy ? 200 : 404);
    }

    /** Fallback English text; the frontend prefers its own translation of `code`. */
    private function messageFor(?string $code): ?string
{
    return match ($code) {
        'not_borrowed' => 'This copy is not currently borrowed, so it cannot be returned.',
        'copy_unavailable' => 'This copy is damaged or unavailable for borrowing.',
        default => null,
    };
}

    /** GET /scan/history - filterable scan log (admin, librarian). */
    public function index(Request $request)
    {
        $query = ScanHistory::with(['user:id,name', 'bookCopy.book:id,title'])
            ->orderByDesc('created_at');

        if ($request->filled('scan_type')) {
            $query->where('scan_type', $request->scan_type);
        }

        if ($request->filled('scan_result')) {
            $query->where('scan_result', $request->scan_result);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('barcode_scanned', 'ilike', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$search}%"));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    /** GET /scan/summary - scan counts (admin, librarian). */
    public function summary()
    {
        return response()->json([
            'total_scans' => ScanHistory::count(),
            'success_count' => ScanHistory::where('scan_result', 'success')->count(),
            'not_found_count' => ScanHistory::where('scan_result', 'not_found')->count(),
            'error_count' => ScanHistory::where('scan_result', 'error')->count(),
            'today_count' => ScanHistory::whereDate('created_at', now()->toDateString())->count(),
        ]);
    }
}