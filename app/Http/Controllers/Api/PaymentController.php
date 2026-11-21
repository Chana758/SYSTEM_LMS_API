<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fine;
use App\Models\Payment;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use Illuminate\Http\Request;

/** B5.1-B5.4: KHQR payments, bank callback and payment history. */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments)
    {
    }

    /** POST /fines/{fine}/khqr - the member who owns the fine, or staff at the desk. */
    public function payFine(Request $request, Fine $fine)
    {
        $user = $request->user();
        $isStaff = $this->isStaff($request);

        if (! $isStaff && $fine->borrow?->member?->user_id !== $user->id) {
            return response()->json(['message' => __('payments.NOT_YOUR_FINE')], 403);
        }

        $currency = $this->currency($request);
        $result = $this->payments->startForFine($fine->load('borrow.member.user'), $user, $isStaff ? $user : null, $currency);

        return response()->json($this->present($result['payment'], $result['qr_image'], $result['deeplink']), 201);
    }

    /** GET /payments/{payment}/status - polled by the QR screen every few seconds. */
    public function status(Request $request, Payment $payment)
    {
        $this->authorizeView($request, $payment);

        return response()->json($this->present($this->payments->refresh($payment)));
    }

    /**
     * POST /payments/payway/callback (public). PayWay calls this after a payment.
     * The body is NOT trusted: we only use tran_id to find the payment, then ask the bank.
     */
    public function paywayCallback(Request $request)
    {
        $tranId = (string) $request->input('tran_id', '');
        $payment = $tranId !== '' ? Payment::where('tran_id', $tranId)->first() : null;

        if ($payment) {
            $this->payments->refresh($payment, force: true);
        }

        return response()->json(['ok' => true]);
    }

    /** GET /payments - staff history of every payment, KHQR and cash (B5.4). */
    public function index(Request $request)
    {
        $query = Payment::with('user:id,name,email')->latest();

        foreach (['status', 'method', 'purpose'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('tran_id', 'ilike', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$search}%"));
            });
        }

        $summary = [
            // Totals in Riel (amount_khr), whatever currency each payment used
            'refunded_total' => (float) (clone $query)->reorder()->where('status', 'refunded')->sum('amount_khr'),
            'paid_total' => (float) (clone $query)->reorder()->where('status', Payment::PAID)->sum('amount_khr'),
            'paid_count' => (clone $query)->reorder()->where('status', Payment::PAID)->count(),
            'khqr_total' => (float) (clone $query)->reorder()->where('status', Payment::PAID)->where('method', 'khqr')->sum('amount_khr'),
            'cash_total' => (float) (clone $query)->reorder()->where('status', Payment::PAID)->where('method', 'cash')->sum('amount_khr'),
        ];

        return response()->json(
            $query->paginate(min((int) $request->input('per_page', 20), 100))->toArray() + ['summary' => $summary]
        );
    }

    /** GET /my-payments - a member's own receipts. */
    public function mine(Request $request)
    {
        return response()->json(
            Payment::where('user_id', $request->user()->id)
                ->latest()
                ->paginate(min((int) $request->input('per_page', 15), 50))
        );
    }

    /** POST /payments/{payment}/simulate-paid - staff demo button, fake driver only. */
    public function simulatePaid(Request $request, Payment $payment)
    {
        if ($this->payments->driver() !== 'fake' || app()->environment('production')) {
            return response()->json(['message' => __('payments.SIMULATE_ONLY_TEST')], 403);
        }

        FakeGateway::markPaid($payment);

        return response()->json($this->present($this->payments->refresh($payment, force: true)));
    }

    /** GET /payment-config: exchange rate + default currency for the ៛/$ switch. */
    public function config()
    {
        return response()->json(Money::publicConfig());
    }

    /** "currency" from the request: KHR (default) or USD. */
    public function currency(Request $request): string
    {
        $request->validate(['currency' => ['nullable', 'in:KHR,USD,khr,usd']]);

        return strtoupper((string) $request->input('currency', 'KHR')) === 'USD' ? 'USD' : 'KHR';
    }

    /** Shared with DepositController so both screens get the same shape. */
    public function presentPayment(Payment $payment, ?string $qrImage = null, ?string $deeplink = null): array
    {
        return $this->present($payment, $qrImage, $deeplink);
    }

    private function present(Payment $payment, ?string $qrImage = null, ?string $deeplink = null): array
    {
        return [
            'id'         => $payment->id,
            'tran_id'    => $payment->tran_id,
            'purpose'    => $payment->purpose,
            'amount'     => (float) $payment->amount,
            'currency'   => $payment->currency,
            'amount_khr' => (float) $payment->amount_khr,
            'exchange_rate' => $payment->exchange_rate ? (float) $payment->exchange_rate : null,
            'method'     => $payment->method,
            'provider'   => $payment->provider,
            'status'     => $payment->status,
            'qr_string'  => $payment->isPending() ? $payment->qr_string : null,
            'qr_image'   => $qrImage,
            'deeplink'   => $deeplink,
            'expires_at' => $payment->expires_at,
            'paid_at'    => $payment->paid_at,
            'apv'        => $payment->apv,
            'test_mode'  => $payment->provider === 'fake',
        ];
    }

    private function authorizeView(Request $request, Payment $payment): void
    {
        abort_unless($this->isStaff($request) || $payment->user_id === $request->user()->id, 403);
    }

    private function isStaff(Request $request): bool
    {
        return in_array($request->user()->role?->name, ['admin', 'librarian'], true);
    }
}
