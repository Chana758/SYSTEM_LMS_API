<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/** Picks the gateway from config/payment.php (PAYMENT_DRIVER in .env). */
class GatewayFactory
{
    public static function make(): PaymentGateway
    {
        return match (config('payment.driver')) {
            'payway' => new PayWayGateway(
                rtrim((string) config('payment.payway.base_url'), '/'),
                (string) config('payment.payway.merchant_id'),
                (string) config('payment.payway.api_key'),
                (string) config('payment.payway.qr_template', 'template3_color'),
                (int) config('payment.payway.timeout', 15),
            ),
            'bakong' => new BakongGateway(
                rtrim((string) config('payment.bakong.base_url'), '/'),
                (string) config('payment.bakong.token'),
                (string) config('payment.bakong.account_id'),
                (string) config('payment.bakong.merchant_name'),
                (string) config('payment.bakong.merchant_city'),
                (int) config('payment.bakong.timeout', 15),
            ),
            'fake'  => new FakeGateway(),
            default => throw new InvalidArgumentException('Unknown PAYMENT_DRIVER: ' . config('payment.driver')),
        };
    }
}
