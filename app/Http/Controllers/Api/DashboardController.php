<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BorrowTransaction;
use App\Models\Member;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'total_books'        => (int) Book::sum('total_qty'),
            'available_books'    => (int) Book::sum('available_qty'),
            'total_members'      => Member::where('status', 'active')->count(),
            'borrowed_today'     => BorrowTransaction::whereDate('borrow_date', today())->count(),
            'currently_borrowed' => BorrowTransaction::where('status', 'borrowed')->count(),
            'overdue_count'      => BorrowTransaction::where('status', 'borrowed')->where('due_date', '<', now())->count(),
            'unpaid_fines_total' => (float) BorrowTransaction::where('fine_status', 'unpaid')->sum('fine_amount'),
            'low_stock_books'    => Book::where('available_qty', '<=', 2)
                                        ->select('id', 'title', 'available_qty', 'total_qty')
                                        ->limit(5)
                                        ->get(),
        ]);
    }
}