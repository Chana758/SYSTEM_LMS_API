<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\BorrowTransaction;
use App\Models\ScanHistory;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    /**
     * POST /scan
     * Any authenticated user (in practice: admin/librarian at the front
     * desk) — process a scanned barcode, cross-check its real-time state,
     * and log the attempt for audit purposes.
     */
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
        $result = 'not_found';

        if ($bookCopy) {
            $result = 'success';

            // FIX: fetch the active (still-borrowed) transaction for this
            // copy REGARDLESS of scan_type. All three flows need it:
            //   - lookup: staff need to see "Borrowed by X" even when just
            //             checking a book's status.
            //   - return: needed to know what to return / compute overdue.
            //   - borrow: needed so a copy that's already checked out can
            //             offer "Add to Reservation Queue" instead of a
            //             dead-end error.
            // Previously this was only computed for scan_type === 'return',
            // which silently broke the lookup banner and the borrow
            // reservation flow for every other scan type.
            $activeBorrow = BorrowTransaction::with('member.user:id,name')
                ->where('book_copy_id', $bookCopy->id)
                ->where('status', 'borrowed')
                ->latest('borrow_date')
                ->first();

            if ($validated['scan_type'] === 'return') {
                if (!$activeBorrow) {
                    // Copy exists but nothing is currently borrowed against
                    // it — flag as an error rather than a false "success"
                    // so staff aren't misled into thinking a return happened.
                    $result = 'error';
                }
            }

            if ($validated['scan_type'] === 'borrow' && $bookCopy->status !== 'available') {
                // FIX: only treat this as a hard error when there is truly
                // nothing to act on (e.g. a damaged/lost copy with no
                // active loan). If the copy is checked out AND we found
                // its active_borrow, keep result = 'success' so the
                // frontend can render the "reserve for member" flow
                // instead of a dead-end error banner.
                if (!$activeBorrow) {
                    $result = 'error';
                }
            }

            // scan_type === 'lookup' never forces an error based on
            // status — it's read-only, so any found copy is a 'success'
            // and active_borrow (if any) is simply shown for context.
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
            'book_copy' => $bookCopy,
            'active_borrow' => $activeBorrow,
            'log' => $log,
        ], $bookCopy ? 200 : 404);
    }

    /**
     * GET /scan/history
     * Accessible by: admin, librarian
     */
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

    /**
     * GET /scan/summary
     * Accessible by: admin, librarian
     */
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