<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fine\StoreFineRequest;
use App\Models\Fine;
use App\Models\Member;
use App\Models\Notification;
use App\Support\CurrencyHelper;
use Illuminate\Http\Request;

class FineController extends Controller
{
    /** List all fines with status, reason and member-name filters (staff). */
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

    /** List the logged-in member's own fines. */
    public function myFines(Request $request)
    {
        $member = Member::where('user_id', $request->user()->id)->firstOrFail();

        $query = Fine::with(['borrow.bookCopy.book'])
            ->whereHas('borrow', fn ($q) => $q->where('member_id', $member->id))
            ->latest();

        if ($request->filled('status')) $query->where('status', $request->status);

        return response()->json($query->paginate($request->get('per_page', 15)));
    }

    /** Create a fine (amount converted from Riel to USD) and notify the member. */
    public function store(StoreFineRequest $request)
    {
        $validated = $request->validated();
        $validated['amount'] = CurrencyHelper::khrToUsd($validated['amount']);

        $fine = Fine::create($validated);
        $fine->load('borrow.member.user', 'borrow.bookCopy.book');

        $this->notifyMember(
            $fine,
            'Fine Issued',
            sprintf(
                'A fine of $%s has been issued for %s.',
                number_format($fine->amount, 2),
                $this->reasonLabel($fine)
            )
        );

        return response()->json([
            'message' => 'Fine created successfully.',
            'data' => $fine,
        ], 201);
    }

    /** Show one fine with its borrow, member and book. */
    public function show(Fine $fine)
    {
        return response()->json($fine->load(['borrow.member.user', 'borrow.bookCopy.book']));
    }

    /** Edit the amount, reason or notes of an unpaid fine. */
    public function update(Request $request, Fine $fine)
    {
        if ($fine->status !== 'unpaid') {
            return response()->json([
                'message' => 'Only unpaid fines can be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'in:overdue,damaged,lost,other'],
            'notes'  => ['nullable', 'string'],
        ]);

        $fine->update($validated);

        $fine = $fine->fresh()->load('borrow.member.user', 'borrow.bookCopy.book');

        return response()->json([
            'message' => 'Fine updated successfully.',
            'data' => $fine,
        ]);
    }

    /** Mark a fine as paid with a payment method and notify the member. */
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

        $fine = $fine->fresh()->load('borrow.member.user', 'borrow.bookCopy.book');

        $this->notifyMember(
            $fine,
            'Fine Payment Received',
            sprintf('Your fine payment of $%s has been received.', number_format($fine->amount, 2))
        );

        return response()->json([
            'message' => 'Fine paid successfully.',
            'data' => $fine,
        ]);
    }

    /** Waive an unpaid fine and notify the member. */
    public function waive(Request $request, Fine $fine)
    {
        if ($fine->status !== 'unpaid') {
            return response()->json(['message' => 'This fine cannot be waived.'], 422);
        }

        $fine->update([
            'status' => 'waived',
            'notes' => $request->input('notes', $fine->notes),
        ]);

        $fine = $fine->fresh()->load('borrow.member.user', 'borrow.bookCopy.book');

        $this->notifyMember(
            $fine,
            'Fine Waived',
            sprintf('Your fine of $%s has been waived by the librarian.', number_format($fine->amount, 2))
        );

        return response()->json([
            'message' => 'Fine waived successfully.',
            'data' => $fine,
        ]);
    }

    /** Return total unpaid, paid and waived amounts plus the unpaid count. */
    public function summary()
    {
        return response()->json([
            'total_unpaid' => Fine::where('status', 'unpaid')->sum('amount'),
            'total_paid' => Fine::where('status', 'paid')->sum('amount'),
            'total_waived' => Fine::where('status', 'waived')->sum('amount'),
            'count_unpaid' => Fine::where('status', 'unpaid')->count(),
        ]);
    }

    /** Send a fine notification to the member who owns the fine. */
    private function notifyMember(Fine $fine, string $title, string $message): void
    {
        $userId = $fine->borrow?->member?->user_id;

        if (!$userId) {
            return;
        }

        Notification::create([
            'user_id' => $userId,
            'title'   => $title,
            'message' => $message,
            'type'    => 'fine',
            'link'    => '/my-fines',
            'is_read' => false,
            'sent_at' => now(),
        ]);
    }

    /** Turn a fine reason into a readable phrase for notification messages. */
    private function reasonLabel(Fine $fine): string
    {
        return match ($fine->reason) {
            'damaged' => 'a damaged book',
            'lost' => 'a lost book',
            'overdue' => 'an overdue book',
            default => $fine->reason ?? 'a library fine',
        };
    }
}