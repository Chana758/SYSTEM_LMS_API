<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/**
 * Builds a dynamic Bakong KHQR string (EMVCo TLV) for an individual Bakong account.
 * Same layout as the KHQR SDKs (NBC spec): 00 format, 01 dynamic, 29 account,
 * 52 MCC, 53 currency, 54 amount, 58 KH, 59 name, 60 city, 62 bill/store/terminal,
 * 99 created/expires timestamps (ms), 63 CRC-16/CCITT-FALSE.
 * The MD5 of the whole string is what Bakong's check_transaction_by_md5 looks up.
 */
class KhqrBuilder
{
    public const CURRENCY_KHR = '116';
    public const CURRENCY_USD = '840';

    public static function individual(
        string $bakongAccountId,
        string $merchantName,
        string $merchantCity,
        float $amount,
        string $currency = 'KHR',
        ?string $billNumber = null,
        ?string $storeLabel = null,
        ?string $terminalLabel = null,
        int $expiresInMinutes = 10,
        ?int $nowMs = null,
    ): string {
        self::assertMax($bakongAccountId, 32, 'Bakong account ID');
        if (! str_contains($bakongAccountId, '@')) {
            throw new InvalidArgumentException('Bakong account ID must look like name@bank');
        }

        $currencyCode = strtoupper($currency) === 'USD' ? self::CURRENCY_USD : self::CURRENCY_KHR;

        $qr = self::tlv('00', '01')
            . self::tlv('01', '12')                                  // dynamic QR (has an amount)
            . self::tlv('29', self::tlv('00', $bakongAccountId))     // individual Bakong account
            . self::tlv('52', '5999')
            . self::tlv('53', $currencyCode)
            . self::tlv('54', self::formatAmount($amount, $currencyCode))
            . self::tlv('58', 'KH')
            . self::tlv('59', self::clip($merchantName, 25))
            . self::tlv('60', self::clip($merchantCity, 15));

        $additional = '';
        if ($billNumber) {
            $additional .= self::tlv('01', self::clip($billNumber, 25));
        }
        if ($storeLabel) {
            $additional .= self::tlv('03', self::clip($storeLabel, 25));
        }
        if ($terminalLabel) {
            $additional .= self::tlv('07', self::clip($terminalLabel, 25));
        }
        if ($additional !== '') {
            $qr .= self::tlv('62', $additional);
        }

        $now = $nowMs ?? (int) floor(microtime(true) * 1000);
        $expires = $now + max(1, $expiresInMinutes) * 60 * 1000;
        $qr .= self::tlv('99', self::tlv('00', (string) $now) . self::tlv('01', (string) $expires));

        $qr .= '6304';

        return $qr . self::crc16($qr);
    }

    public static function md5(string $qr): string
    {
        return md5($qr);
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF), 4 uppercase hex digits. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($b = 0; $b < 8; $b++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /** True when the last 4 characters are the correct CRC of everything before them. */
    public static function verifyCrc(string $qr): bool
    {
        return strlen($qr) > 8 && strtoupper(substr($qr, -4)) === self::crc16(substr($qr, 0, -4));
    }

    private static function tlv(string $tag, string $value): string
    {
        $len = mb_strlen($value, 'UTF-8');
        if ($len > 99) {
            throw new InvalidArgumentException("KHQR field {$tag} is too long");
        }

        return $tag . str_pad((string) $len, 2, '0', STR_PAD_LEFT) . $value;
    }

    /** KHR: whole Riel (min 100). USD: at most 2 decimals, no trailing zeros. */
    private static function formatAmount(float $amount, string $currencyCode): string
    {
        if ($currencyCode === self::CURRENCY_KHR) {
            if ($amount < 100 || floor($amount) != $amount) {
                throw new InvalidArgumentException('KHR amount must be a whole number of at least 100');
            }

            return (string) (int) $amount;
        }

        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private static function clip(string $value, int $max): string
    {
        // KHQR text fields are ASCII; drop anything else so bank apps can read it.
        $ascii = trim(preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');

        return substr($ascii, 0, $max);
    }

    private static function assertMax(string $value, int $max, string $label): void
    {
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException("{$label} must be 1-{$max} characters");
        }
    }
}
