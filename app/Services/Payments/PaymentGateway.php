<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * A bank that can show a KHQR and tell us whether it was paid (B5.1).
 * PayWay today; Bakong KHQR can be added later with the same two methods.
 */
interface PaymentGateway
{
    /**
     * Ask the bank for a QR for this payment.
     *
     * @return array{qr_string: string, qr_image: ?string, deeplink: ?string, raw: array}
     */
    public function createQr(Payment $payment, array $customer = []): array;

    /**
     * Ask the bank for the real status. Never trust the browser or the callback body.
     *
     * @return array{status: string, amount: ?float, currency: ?string, apv: ?string, raw: array}
     *         status is one of: paid, pending, failed, cancelled, unknown
     */
    public function checkStatus(Payment $payment): array;

    public function name(): string;
}
