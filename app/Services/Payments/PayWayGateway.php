<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ABA PayWay QR API (KHQR).
 * Docs: developer.payway.com.kh  - generate-qr and check-transaction-2.
 * Every request is signed: base64(HMAC-SHA512(fields in a fixed order, api_key)).
 */
class PayWayGateway implements PaymentGateway
{
    public function __construct(
        private string $baseUrl,
        private string $merchantId,
        private string $apiKey,
        private string $qrTemplate = 'template3_color',
        private int $timeout = 15,
    ) {
        if ($merchantId === '' || $apiKey === '') {
            throw new RuntimeException('PAYWAY_MERCHANT_ID and PAYWAY_API_KEY must be set in .env');
        }
    }

    public function name(): string
    {
        return 'payway';
    }

    public function createQr(Payment $payment, array $customer = []): array
    {
        $fields = [
            'req_time'          => $this->reqTime(),
            'merchant_id'       => $this->merchantId,
            'tran_id'           => $payment->tran_id,
            'amount'            => $this->formatAmount($payment),
            'items'             => '',
            'first_name'        => mb_substr($customer['first_name'] ?? '', 0, 20),
            'last_name'         => mb_substr($customer['last_name'] ?? '', 0, 20),
            'email'             => mb_substr($customer['email'] ?? '', 0, 50),
            'phone'             => mb_substr($customer['phone'] ?? '', 0, 20),
            'purchase_type'     => 'purchase',
            'payment_option'    => 'abapay_khqr',
            'callback_url'      => base64_encode(route('payments.payway.callback')),
            'return_deeplink'   => '',
            'currency'          => $payment->currency,
            'custom_fields'     => '',
            'return_params'     => '',
            'payout'            => '',
            'lifetime'          => (string) max(3, (int) config('payment.qr_lifetime_minutes', 10)),
            'qr_image_template' => $this->qrTemplate,
        ];

        // Hash order is fixed by PayWay; it is the same order as the array above.
        $fields['hash'] = $this->sign(array_values($fields));

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->post($this->baseUrl . '/api/payment-gateway/v1/payments/generate-qr', $fields);

        $body = $response->json() ?? [];
        $code = (string) ($body['status']['code'] ?? '');

        if (! $response->successful() || ! in_array($code, ['0', '00'], true) || empty($body['qrString'])) {
            throw new RuntimeException('PayWay QR failed: ' . ($body['status']['message'] ?? $response->status()) . " (code {$code})");
        }

        return [
            'qr_string' => $body['qrString'],
            'qr_image'  => $body['qrImage'] ?? null,
            'deeplink'  => $body['abapay_deeplink'] ?? null,
            'raw'       => ['status' => $body['status'] ?? null],
        ];
    }

    public function checkStatus(Payment $payment): array
    {
        $reqTime = $this->reqTime();

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->post($this->baseUrl . '/api/payment-gateway/v1/payments/check-transaction-2', [
                'req_time'    => $reqTime,
                'merchant_id' => $this->merchantId,
                'tran_id'     => $payment->tran_id,
                'hash'        => $this->sign([$reqTime, $this->merchantId, $payment->tran_id]),
            ]);

        $body = $response->json() ?? [];
        $data = $body['data'] ?? [];

        // payment_status_code: 0 approved, 2 pending, 3 declined, 4 refunded, 7 cancelled
        $status = match (isset($data['payment_status_code']) ? (int) $data['payment_status_code'] : null) {
            0       => 'paid',
            2       => 'pending',
            3, 4    => 'failed',
            7       => 'cancelled',
            default => 'unknown', // e.g. "transaction not found" before the customer scans
        };

        return [
            'status'   => $status,
            'amount'   => isset($data['total_amount']) ? (float) $data['total_amount'] : null,
            'currency' => $data['payment_currency'] ?? null,
            'apv'      => $data['apv'] ?? null,
            'raw'      => ['status' => $body['status'] ?? null, 'data' => $data],
        ];
    }

    /** base64(HMAC-SHA512(concatenated values, api_key)) */
    private function sign(array $values): string
    {
        return base64_encode(hash_hmac('sha512', implode('', $values), $this->apiKey, true));
    }

    /** UTC, YYYYMMDDHHmmss */
    private function reqTime(): string
    {
        return gmdate('YmdHis');
    }

    /** KHR has no decimals; USD uses two. */
    private function formatAmount(Payment $payment): string
    {
        return $payment->currency === 'KHR'
            ? (string) (int) round((float) $payment->amount)
            : number_format((float) $payment->amount, 2, '.', '');
    }
}
