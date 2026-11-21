<?php

namespace App\Services\Payments;

use App\Models\Fine;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * B5.1-B5.3: the one place money is handled.
 *
 * Rules:
 * - The amount always comes from the database (the fine), never from the request.
 * - A payment becomes "paid" only after the bank's check-transaction says so,
 *   and only if the bank's amount matches ours.
 * - Marking paid is idempotent: a second callback or poll changes nothing.
 *
 * Other modules (deposit B6.2, e-book rental B8.3) add a purpose here and call create().
 */
class PaymentService
{
    private ?PaymentGateway $gateway;

    public function __construct(?PaymentGateway $gateway = null)
    {
        $this->gateway = $gateway; // made on first KHQR use, so cash works without bank keys
    }

    private function gateway(): PaymentGateway
    {
        return $this->gateway ??= GatewayFactory::make();
    }

    public function driver(): string
    {
        return $this->gateway()->name();
    }

    /** Start (or reuse) a KHQR payment for a member's deposit (B6.2). */
    public function startForDeposit(\App\Models\Member $member, User $payer, ?User $staff = null, string $currency = 'KHR'): array
    {
        app(\App\Services\DepositService::class)->assertUnpaid($member);

        return $this->start($member, $member->user, 'deposit', (float) $member->deposit_amount, $staff, $currency);
    }

    /** Start (or reuse) a KHQR payment for one unpaid fine. */
    public function startForFine(Fine $fine, User $payer, ?User $staff = null, string $currency = 'KHR'): array
    {
        if ($fine->status !== 'unpaid') {
            throw ValidationException::withMessages(['fine' => __('payments.FINE_NOT_UNPAID')]);
        }

        $owner = $fine->borrow?->member?->user;
        if (! $owner) {
            throw ValidationException::withMessages(['fine' => __('payments.FINE_HAS_NO_MEMBER')]);
        }

        return $this->start($fine, $owner, 'fine', (float) $fine->amount, $staff, $currency);
    }

    /**
     * Generic start: reuse a pending, unexpired QR for the same item and currency,
     * otherwise ask the bank for a new one. $amountKhr is what the library is owed in Riel;
     * $currency (KHR or USD) is what the payer chose to pay in.
     *
     * @return array{payment: Payment, qr_image: ?string, deeplink: ?string}
     */
    public function start($payable, User $owner, string $purpose, float $amountKhr, ?User $staff = null, string $currency = 'KHR'): array
    {
        $currency = strtoupper($currency) === 'USD' ? 'USD' : 'KHR';

        if ($amountKhr < 100) {
            throw ValidationException::withMessages(['amount' => __('payments.AMOUNT_TOO_SMALL')]);
        }

        $rate = Money::rate();
        $amount = Money::charge($amountKhr, $currency, $rate);

        $existing = Payment::where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->where('status', Payment::PENDING)
            ->latest()
            ->first();

        if ($existing && ! $existing->isExpired() && $existing->currency === $currency && (float) $existing->amount === $amount) {
            return ['payment' => $existing, 'qr_image' => null, 'deeplink' => null];
        }

        if ($existing) {
            $existing->update(['status' => Payment::EXPIRED]);
        }

        $payment = Payment::create([
            'tran_id'      => $this->newTranId(),
            'user_id'      => $owner->id,
            'payable_type' => $payable->getMorphClass(),
            'payable_id'   => $payable->getKey(),
            'purpose'      => $purpose,
            'amount'       => $amount,
            'currency'     => $currency,
            'amount_khr'   => $amountKhr,
            'exchange_rate'=> $currency === 'USD' ? $rate : null,
            'method'       => 'khqr',
            'provider'     => $this->gateway()->name(),
            'status'       => Payment::PENDING,
            'expires_at'   => now()->addMinutes((int) config('payment.qr_lifetime_minutes', 10)),
            'created_by'   => $staff?->id,
        ]);

        try {
            $qr = $this->gateway()->createQr($payment, $this->customer($owner));
        } catch (\Throwable $e) {
            $payment->update(['status' => Payment::FAILED, 'provider_response' => ['error' => $e->getMessage()]]);
            Log::warning('KHQR create failed', ['tran_id' => $payment->tran_id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['payment' => __('payments.BANK_ERROR')]);
        }

        $payment->update(['qr_string' => $qr['qr_string'], 'provider_response' => $qr['raw']]);

        return ['payment' => $payment->fresh(), 'qr_image' => $qr['qr_image'], 'deeplink' => $qr['deeplink']];
    }

    /**
     * Ask the bank and update the payment. Safe to call many times (polling + callback).
     * $force skips the small wait between two checks (used by the bank callback).
     */
    public function refresh(Payment $payment, bool $force = false): Payment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        $interval = (int) config('payment.check_interval_seconds', 5);
        if (! $force && $payment->last_checked_at && $payment->last_checked_at->gt(now()->subSeconds($interval))) {
            return $payment;
        }

        $payment->update(['last_checked_at' => now()]);

        try {
            $result = $this->gateway()->checkStatus($payment);
        } catch (\Throwable $e) {
            Log::warning('KHQR check failed', ['tran_id' => $payment->tran_id, 'error' => $e->getMessage()]);
            return $payment;
        }

        if ($result['status'] === 'paid') {
            return $this->markPaid($payment, $result);
        }

        if (in_array($result['status'], ['failed', 'cancelled'], true)) {
            $payment->update(['status' => $result['status'] === 'failed' ? Payment::FAILED : Payment::CANCELLED]);
        } elseif ($payment->isExpired() && $result['status'] !== 'pending') {
            $payment->update(['status' => Payment::EXPIRED]);
        }

        return $payment->fresh();
    }

    /**
     * Cash at the desk for a fine (B5.4). Received in Riel and/or Dollars;
     * nothing entered = the exact amount in Riel.
     */
    public function recordCash(Fine $fine, User $staff, float $receivedKhr = 0, float $receivedUsd = 0): Payment
    {
        return DB::transaction(function () use ($fine, $staff, $receivedKhr, $receivedUsd) {
            $fine = Fine::whereKey($fine->id)->lockForUpdate()->firstOrFail();

            if ($fine->status !== 'unpaid') {
                throw ValidationException::withMessages(['fine' => __('payments.FINE_NOT_UNPAID')]);
            }

            $payment = $this->cash(
                $fine, (int) ($fine->borrow?->member?->user_id ?? $staff->id), 'fine',
                (float) $fine->amount, $staff, $receivedKhr, $receivedUsd
            );

            $fine->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => 'cash']);

            return $payment;
        });
    }

    /**
     * One cash payment row. The amount owed comes from the database; the cash received
     * (៛ and/or $ at today's rate) must cover it; change is given in Riel, rounded down to 100៛.
     */
    public function cash($payable, int $ownerId, string $purpose, float $amountKhr, User $staff, float $receivedKhr = 0, float $receivedUsd = 0): Payment
    {
        $receivedKhr = max(0, round($receivedKhr));
        $receivedUsd = max(0, round($receivedUsd, 2));

        if ($receivedKhr == 0 && $receivedUsd == 0) {
            $receivedKhr = $amountKhr; // exact change in Riel
        }

        if ($receivedKhr > 100000000 || $receivedUsd > 25000) {
            throw ValidationException::withMessages(['received' => __('payments.CASH_TOO_MUCH')]);
        }

        $rate = Money::rate();
        $totalKhr = $receivedKhr + $receivedUsd * $rate;

        if ($totalKhr + 0.5 < $amountKhr) {
            throw ValidationException::withMessages(['received' => __('payments.CASH_NOT_ENOUGH', [
                'short' => number_format($amountKhr - $totalKhr),
            ])]);
        }

        $change = floor(($totalKhr - $amountKhr) / 100) * 100;

        return Payment::create([
            'tran_id'           => $this->newTranId(),
            'user_id'           => $ownerId,
            'payable_type'      => $payable->getMorphClass(),
            'payable_id'        => $payable->getKey(),
            'purpose'           => $purpose,
            'amount'            => $amountKhr,
            'currency'          => 'KHR',
            'amount_khr'        => $amountKhr,
            'exchange_rate'     => $receivedUsd > 0 ? $rate : null,
            'cash_received_khr' => $receivedKhr,
            'cash_received_usd' => $receivedUsd,
            'change_khr'        => $change,
            'method'            => 'cash',
            'provider'          => 'desk',
            'status'            => Payment::PAID,
            'paid_at'           => now(),
            'created_by'        => $staff->id,
        ]);
    }

    private function markPaid(Payment $payment, array $result): Payment
    {
        return DB::transaction(function () use ($payment, $result) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($payment->status === Payment::PAID) {
                return $payment; // already handled by another request
            }

            // The bank must have charged exactly what we asked for, in the same currency.
            $wrongCurrency = ! empty($result['currency']) && strtoupper((string) $result['currency']) !== $payment->currency;
            if ($wrongCurrency || ($result['amount'] !== null && abs($result['amount'] - (float) $payment->amount) > 0.009)) {
                $payment->update(['status' => Payment::FAILED, 'provider_response' => ['amount_mismatch' => $result['raw']]]);
                Log::error('KHQR amount mismatch', ['tran_id' => $payment->tran_id, 'bank' => $result['amount'], 'ours' => $payment->amount]);
                return $payment;
            }

            $payment->update([
                'status'            => Payment::PAID,
                'paid_at'           => now(),
                'apv'               => $result['apv'],
                'provider_response' => $result['raw'],
            ]);

            $this->applyToPayable($payment);

            return $payment;
        });
    }

    /** What "paid" means for each purpose. */
    private function applyToPayable(Payment $payment): void
    {
        if ($payment->purpose === 'fine') {
            $fine = Fine::whereKey($payment->payable_id)->lockForUpdate()->first();

            if ($fine && $fine->status === 'unpaid') {
                $fine->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => 'khqr']);
            }

            Notification::create([
                'user_id' => $payment->user_id,
                'title'   => __('payments.NOTIFY_PAID_TITLE'),
                'message' => __('payments.NOTIFY_PAID_BODY', ['amount' => number_format((float) $payment->amount_khr)]),
                'type'    => 'fine',
                'link'    => '/my-fines',
                'is_read' => false,
                'sent_at' => now(),
            ]);
        }

        if ($payment->purpose === 'deposit') {
            $member = \App\Models\Member::whereKey($payment->payable_id)->lockForUpdate()->first();
            if ($member) {
                app(\App\Services\DepositService::class)->markPaid($member);
            }
        }
        // 'ebook_rental' (B8.3) is added here when the e-book module is built.
    }

    private function customer(User $user): array
    {
        $parts = preg_split('/\s+/', trim((string) $user->name), 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name'  => $parts[1] ?? '',
            'email'      => (string) $user->email,
            'phone'      => (string) $user->phone,
        ];
    }

    /** 20 characters max (PayWay): "LIB" + yymmddHHMMSS + 5 random. */
    public function newTranId(): string
    {
        do {
            $id = 'LIB' . now()->format('ymdHis') . strtoupper(Str::random(5));
        } while (Payment::where('tran_id', $id)->exists());

        return $id;
    }
}
