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

            // FIX/ENHANCEMENT: for a 'return' scan, look up the active
            // (still-borrowed) transaction for this copy so the frontend
            // can immediately show a "Confirm Return" action without a
            // second manual lookup step.
            if ($validated['scan_type'] === 'return') {
                $activeBorrow = BorrowTransaction::with('member.user:id,name')
                    ->where('book_copy_id', $bookCopy->id)
                    ->where('status', 'borrowed')
                    ->latest('borrow_date')
                    ->first();

                if (!$activeBorrow) {
                    // Copy exists but nothing is currently borrowed against
                    // it — flag as an error rather than a false "success"
                    // so staff aren't misled into thinking a return happened.
                    $result = 'error';
                }
            }

            // FIX/ENHANCEMENT: for a 'borrow' scan, make sure the physical
            // copy is actually available before staff proceeds.
            if ($validated['scan_type'] === 'borrow' && $bookCopy->status !== 'available') {
                $result = 'error';
            }
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

        // FIX/ENHANCEMENT: added result + search + date-range filters to
        // match the filtering pattern already used in FineController.
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
     * FIX/ENHANCEMENT: was missing — powers the stat cards on the
     * Scan History page (total scans, success rate, today's count).
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