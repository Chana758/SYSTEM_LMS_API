<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bakong KHQR (National Bank of Cambodia).
 * - QR is built on our server (KhqrBuilder) and paid into the school's Bakong account.
 * - Status: POST /v1/check_transaction_by_md5 with the Bakong developer token.
 *   responseCode 0 = paid (data has amount/currency/toAccountId), otherwise not paid yet.
 * Token comes from api-bakong.nbc.gov.kh and must be renewed (about every 90 days).
 */
class BakongGateway implements PaymentGateway
{
    public function __construct(
        private string $baseUrl,
        private string $token,
        private string $accountId,
        private string $merchantName,
        private string $merchantCity,
        private int $timeout = 15,
    ) {
        if ($accountId === '') {
            throw new RuntimeException('BAKONG_ACCOUNT_ID must be set in .env (e.g. library@aclb)');
        }
    }

    public function name(): string
    {
        return 'bakong';
    }

    public function createQr(Payment $payment, array $customer = []): array
    {
        $qr = KhqrBuilder::individual(
            bakongAccountId: $this->accountId,
            merchantName: $this->merchantName,
            merchantCity: $this->merchantCity,
            amount: (float) $payment->amount,
            currency: $payment->currency,
            billNumber: $payment->tran_id,
            storeLabel: 'Library',
            terminalLabel: ucfirst(str_replace('_', ' ', $payment->purpose)),
            expiresInMinutes: max(1, (int) config('payment.qr_lifetime_minutes', 10)),
        );

        return [
            'qr_string' => $qr,
            'qr_image'  => null, // the frontend draws the QR from qr_string
            'deeplink'  => $this->deeplink($qr),
            'raw'       => ['md5' => KhqrBuilder::md5($qr)],
        ];
    }

    public function checkStatus(Payment $payment): array
    {
        if ($this->token === '') {
            throw new RuntimeException('BAKONG_TOKEN is not set');
        }

        $md5 = KhqrBuilder::md5((string) $payment->qr_string);

        $response = Http::timeout($this->timeout)
            ->withToken($this->token)
            ->acceptJson()
            ->post($this->baseUrl . '/v1/check_transaction_by_md5', ['md5' => $md5]);

        if ($response->status() === 401) {
            throw new RuntimeException('Bakong token is invalid or expired - renew it at api-bakong.nbc.gov.kh');
        }

        $body = $response->json() ?? [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ((int) ($body['responseCode'] ?? 1) !== 0 || $data === []) {
            return ['status' => 'pending', 'amount' => null, 'currency' => null, 'apv' => null, 'raw' => ['md5' => $md5, 'response' => $body]];
        }

        // Paid - but only count it if the money went to OUR account.
        if (isset($data['toAccountId']) && strcasecmp((string) $data['toAccountId'], $this->accountId) !== 0) {
            Log::error('Bakong payment went to another account', ['tran_id' => $payment->tran_id, 'to' => $data['toAccountId']]);

            return ['status' => 'failed', 'amount' => null, 'currency' => null, 'apv' => null, 'raw' => ['md5' => $md5, 'data' => $data]];
        }

        return [
            'status'   => 'paid',
            'amount'   => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
            'apv'      => isset($data['hash']) ? substr((string) $data['hash'], 0, 8) : null, // short Bakong hash as the reference
            'raw'      => ['md5' => $md5, 'data' => $data],
        ];
    }

    /** Bakong short link that opens a bank app on phones. Optional: null if it fails. */
    private function deeplink(string $qr): ?string
    {
        if ($this->token === '') {
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->token)
                ->acceptJson()
                ->post($this->baseUrl . '/v1/generate_deeplink_by_qr', [
                    'qr' => $qr,
                    'sourceInfo' => [
                        'appIconUrl'          => 'https://bakong.nbc.gov.kh/images/logo.svg',
                        'appName'             => mb_substr($this->merchantName, 0, 25),
                        'appDeepLinkCallback' => rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/my-fines',
                    ],
                ]);

            return $response->json('data.shortLink');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Days until the JWT token expires, or null if it can't be read. */
    public static function tokenDaysLeft(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! isset($payload['exp'])) {
            return null;
        }

        return (int) floor(((int) $payload['exp'] - time()) / 86400);
    }
}
