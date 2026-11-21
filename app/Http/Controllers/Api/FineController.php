<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fine\StoreFineRequest;
use App\Models\Fine;
use App\Models\Member;
use App\Models\Notification;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

/**
 * Fines (Sam). Amounts are stored in Riel (FIN1).
 * Cash at the desk -> pay(); KHQR -> PaymentController::payFine().
 */
class FineController extends Controller
{
    /** List all fines with status, reason and member-name filters (staff). */
    public function index(Request $request)
    {
        $query = Fine::with(['borrow.member.user', 'borrow.bookCopy.book', 'waivedBy:id,name'])->latest();

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

    /** Create a fine (amount in Riel) and notify the member. */
    public function store(StoreFineRequest $request)
    {
        $fine = Fine::create($request->validated());
        $fine->load('borrow.member.user', 'borrow.bookCopy.book');

        $this->notifyMember(
            $fine,
            __('payments.NOTIFY_FINE_TITLE'),
            __('payments.NOTIFY_FINE_BODY', [
                'amount' => number_format((float) $fine->amount),
                'reason' => __('payments.REASON_' . strtoupper($fine->reason ?? 'OTHER')),
            ])
        );

        return response()->json([
            'message' => 'Fine created successfully.',
            'data' => $fine,
        ], 201);
    }

    /** Show one fine with its borrow, member, book and payments. */
    public function show(Fine $fine)
    {
        return response()->json($fine->load([
            'borrow.member.user', 'borrow.bookCopy.book', 'waivedBy:id,name',
            'payments' => fn ($q) => $q->latest(),
        ]));
    }

    /** Staff takes cash at the desk (FIN2). KHQR goes through PaymentController. */
    public function pay(Request $request, Fine $fine, PaymentService $payments)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'in:cash'],
            'received_khr'   => ['nullable', 'numeric', 'min:0'],
            'received_usd'   => ['nullable', 'numeric', 'min:0'],
        ]);

        $payment = $payments->recordCash(
            $fine, $request->user(),
            (float) ($data['received_khr'] ?? 0), (float) ($data['received_usd'] ?? 0)
        );

        $fine = $fine->fresh()->load('borrow.member.user', 'borrow.bookCopy.book');

        $this->notifyMember(
            $fine,
            __('payments.NOTIFY_PAID_TITLE'),
            __('payments.NOTIFY_PAID_BODY', ['amount' => number_format((float) $fine->amount)])
        );

        return response()->json([
            'message' => __('payments.CASH_RECORDED'),
            'data' => $fine,
            'payment_id' => $payment->id,
            'change_khr' => (float) $payment->change_khr,
        ]);
    }

    /** Waive an unpaid fine: a reason is required and the staff member is recorded (FIN3). */
    public function waive(Request $request, Fine $fine)
    {
        $request->validate(['notes' => ['required', 'string', 'min:5', 'max:500']], [
            'notes.required' => __('payments.WAIVE_REASON_REQUIRED'),
            'notes.min'      => __('payments.WAIVE_REASON_REQUIRED'),
        ]);

        if ($fine->status !== 'unpaid') {
            return response()->json(['message' => __('payments.FINE_NOT_UNPAID')], 422);
        }

        $fine->update([
            'status'    => 'waived',
            'notes'     => $request->input('notes'),
            'waived_by' => $request->user()->id,
            'waived_at' => now(),
        ]);

        $fine = $fine->fresh()->load('borrow.member.user', 'borrow.bookCopy.book', 'waivedBy:id,name');

        $this->notifyMember(
            $fine,
            __('payments.NOTIFY_WAIVED_TITLE'),
            __('payments.NOTIFY_WAIVED_BODY', ['amount' => number_format((float) $fine->amount)])
        );

        return response()->json([
            'message' => 'Fine waived successfully.',
            'data' => $fine,
        ]);
    }

    /** Return total unpaid, paid and waived amounts (Riel) plus the unpaid count. */
    public function summary()
    {
        return response()->json([
            'currency' => 'KHR',
            'total_unpaid' => (float) Fine::where('status', 'unpaid')->sum('amount'),
            'total_paid' => (float) Fine::where('status', 'paid')->sum('amount'),
            'total_waived' => (float) Fine::where('status', 'waived')->sum('amount'),
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
}
