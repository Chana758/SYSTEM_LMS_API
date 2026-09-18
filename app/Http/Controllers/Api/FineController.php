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
     * POST /fines
     *
     * `amount` in the request is the RAW Riel value coming from
     * FineForm.vue's create mode (same convention as ReturnForm.vue's
     * damage_fee / lost_fee — no pre-conversion on the frontend side).
     * CurrencyHelper::khrToUsd() does the conversion before saving, so
     * `Fine.amount` in the database is always stored as USD, same as
     * fines created automatically through the borrow/return flow.
     */
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

    public function show(Fine $fine)
    {
        return response()->json($fine->load(['borrow.member.user', 'borrow.bookCopy.book']));
    }

    /**
     * PUT /fines/{fine}
     * Accessible by: admin, librarian (same as store/pay/waive).
     *
     * NEW — previously this endpoint didn't exist at all, even though
     * FineEditPage.vue / FineForm.vue on the frontend already assumed
     * it did (the edit page silently did nothing on submit).
     *
     * Two deliberate design choices, both to avoid a currency bug:
     *
     * 1. Only `unpaid` fines can be edited. Once a fine is paid or
     *    waived it becomes a financial record of what actually
     *    happened — changing it after the fact would make the ledger
     *    (and the member's payment receipt) inconsistent with reality.
     *
     * 2. UNLIKE store(), `amount` here is NOT run through
     *    CurrencyHelper::khrToUsd(). store() accepts a fresh Riel entry
     *    and converts it once. update() instead edits the amount that's
     *    already stored as USD and displayed everywhere in the UI
     *    (fine lists, fine detail, my-fines) — re-running a KHR→USD
     *    conversion on an already-USD value would silently corrupt it.
     *    FineForm.vue's edit mode reflects this: the Amount field is
     *    labeled and edited directly in USD.
     *
     * `borrow_id` is intentionally not editable here — a fine should
     * stay tied to the borrow transaction it was issued for; if that
     * link was wrong, the fine should be waived and a new one created
     * against the correct borrow instead.
     */
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

    public function summary()
    {
        return response()->json([
            'total_unpaid' => Fine::where('status', 'unpaid')->sum('amount'),
            'total_paid' => Fine::where('status', 'paid')->sum('amount'),
            'total_waived' => Fine::where('status', 'waived')->sum('amount'),
            'count_unpaid' => Fine::where('status', 'unpaid')->count(),
        ]);
    }

    /**
     * Create a `fine`-type Notification for the member who owns this fine's
     * borrow record. Silently no-ops if the relation chain is somehow
     * missing a user, so a notification failure never blocks the fine
     * action itself.
     */
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

    /**
     * Human-readable reason for the "Fine Issued" message
     * (e.g. "a damaged book", "a lost book"). Falls back to the raw
     * reason value if it doesn't match a known case.
     */
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