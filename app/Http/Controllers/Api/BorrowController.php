<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookCopy;
use App\Models\BorrowTransaction;
use App\Models\ScanHistory;
use Illuminate\Http\Request;

class ScanController extends Controller
{
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

        if ($bookCopy) {
            $result = 'success';

            // Needed by all scan types (lookup banner, return, reservation flow)
            $activeBorrow = BorrowTransaction::with('member.user:id,name')
                ->where('book_copy_id', $bookCopy->id)
                ->where('status', 'borrowed')
                ->latest('borrow_date')
                ->first();

            // Stock of the whole TITLE (other copies), not just the scanned copy.
            // The reservation API rejects a reservation while ANY copy is free,
            // so the UI must know this before it shows a "reserve" button.
            $copies = BookCopy::where('book_id', $bookCopy->book_id);
            $totalCopies = (clone $copies)->count();
            $availableCopies = (clone $copies)->where('status', 'available')->count();

            $bookStats = [
                'total_copies'     => $totalCopies,
                'available_copies' => $availableCopies,
                // true  -> member can borrow another copy right now
                'can_borrow_other' => $availableCopies > 0,
                // true  -> every copy is out, so joining the queue is allowed
                'can_reserve'      => $availableCopies === 0,
            ];

            // Return scan with nothing borrowed is an error
            if ($validated['scan_type'] === 'return' && !$activeBorrow) {
                $result = 'error';
            }

            // Unavailable copy with no active loan (e.g. damaged/lost) is an error;
            // if it is checked out, stay 'success' so the reservation flow works
            if ($validated['scan_type'] === 'borrow'
                && $bookCopy->status !== 'available'
                && !$activeBorrow) {
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
            'book_stats' => $bookStats,
            'log' => $log,
        ], $bookCopy ? 200 : 404);
    }

    /** GET /scan/history - filterable scan log (admin, librarian). */
    public function index(Request $request)
    {
        $op = \DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

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
            $query->where(function ($q) use ($search, $op) {
                $q->where('barcode_scanned', $op, "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', $op, "%{$search}%"));
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