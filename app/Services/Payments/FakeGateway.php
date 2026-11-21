<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Test gateway for local demos before the PayWay sandbox keys arrive.
 * Its QR cannot be paid by a bank: staff press "Simulate paid" instead.
 * Refused in production.
 */
class FakeGateway implements PaymentGateway
{
    public function __construct()
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The fake payment gateway cannot run in production.');
        }
    }

    public function name(): string
    {
        return 'fake';
    }

    public function createQr(Payment $payment, array $customer = []): array
    {
        return [
            'qr_string' => "TEST-KHQR|{$payment->tran_id}|{$payment->amount} {$payment->currency}",
            'qr_image'  => null,
            'deeplink'  => null,
            'raw'       => ['fake' => true],
        ];
    }

    public function checkStatus(Payment $payment): array
    {
        $paid = Cache::get(self::key($payment)) === true;

        return [
            'status'   => $paid ? 'paid' : 'pending',
            'amount'   => $paid ? (float) $payment->amount : null,
            'currency' => $payment->currency,
            'apv'      => $paid ? 'TEST' : null,
            'raw'      => ['fake' => true],
        ];
    }

    /** Called by the "Simulate paid" button (non-production only). */
    public static function markPaid(Payment $payment): void
    {
        Cache::put(self::key($payment), true, now()->addHour());
    }

    private static function key(Payment $payment): string
    {
        return 'fake-payment-paid:' . $payment->tran_id;
    }
}
