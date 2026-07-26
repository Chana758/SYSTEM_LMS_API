<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fine\StoreFineRequest;
use App\Models\Fine;
use App\Models\Member;
use App\Support\CurrencyHelper;
use Illuminate\Http\Request;

class FineController extends Controller
{
    public function index(Request $request)
    {
        $query = Fine::with(['borrow.member.user', 'borrow.bookCopy.book'])->latest();

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('reason')) $query->where('reason', $request->reason);
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('borrow.member.user', fn ($q) => $q->where('name', 'ilike', "%{$search}%"));
        }

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    public function myFines(Request $request)
    {
        $member = Member::where('user_id', $request->user()->id)->firstOrFail();

        $query = Fine::with(['borrow.bookCopy.book'])
            ->whereHas('borrow', fn ($q) => $q->where('member_id', $member->id))
            ->latest();

        if ($request->filled('status')) $query->where('status', $request->status);

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    /**
     *  FIX: manual fine creation (admin/librarian issuing a fine outside
     * the borrow/return flow) now also goes through CurrencyHelper.
     * Previously this was the ONE remaining gap where a Riel amount
     * entered on the admin form would be stored directly as USD —
     * exactly the inconsistency CurrencyHelper was built to prevent.
     *
     * `amount` in the request is treated as the RAW Riel value coming
     * from the frontend form (same convention as ReturnForm.vue's
     * damage_fee / lost_fee — no pre-conversion on the frontend side).
     */
    public function store(StoreFineRequest $request)
    {
        $validated = $request->validated();
        $validated['amount'] = CurrencyHelper::khrToUsd($validated['amount']);

        $fine = Fine::create($validated);

        return response()->json([
            'message' => 'Fine created successfully.',
            'data' => $fine->load('borrow.member.user', 'borrow.bookCopy.book'),
        ], 201);
    }

    public function show(Fine $fine)
    {
        return response()->json($fine->load(['borrow.member.user', 'borrow.bookCopy.book']));
    }

    public function pay(Request $request, Fine $fine)
    {
        if ($fine->status === 'paid') {
            return response()->json(['message' => 'This fine has already been paid.'], 422);
        }

        $request->validate(['payment_method' => ['required', 'in:cash,card,bank_transfer,other']]);

        $fine->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => $request->payment_method,
        ]);

        return response()->json([
            'message' => 'Fine paid successfully.',
            'data' => $fine->fresh()->load('borrow.member.user', 'borrow.bookCopy.book'),
        ]);
    }

    public function waive(Request $request, Fine $fine)
    {
        if ($fine->status !== 'unpaid') {
            return response()->json(['message' => 'This fine cannot be waived.'], 422);
        }

        $fine->update([
            'status' => 'waived',
            'notes' => $request->input('notes', $fine->notes),
        ]);

        return response()->json([
            'message' => 'Fine waived successfully.',
            'data' => $fine->fresh()->load('borrow.member.user', 'borrow.bookCopy.book'),
        ]);
    }

    public function summary()
    {
        return response()->json([
            'total_unpaid' => Fine::where('status', 'unpaid')->sum('amount'),
            'total_paid' => Fine::where('status', 'paid')->sum('amount'),
            'total_waived' => Fine::where('status', 'waived')->sum('amount'),
            'count_unpaid' => Fine::where('status', 'unpaid')->count(),
        ]);
    }
}