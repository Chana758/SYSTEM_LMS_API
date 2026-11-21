<?php

namespace App\Support;

use App\Models\Setting;

/** Riel <-> Dollar using the rate the admin sets (System Settings > Currency). */
class Money
{
    public static function rate(): float
    {
        $rate = (float) Setting::get('exchange_rate_khr_per_usd', 4100);

        return $rate > 0 ? $rate : 4100;
    }

    public static function defaultCurrency(): string
    {
        return strtoupper((string) Setting::get('default_currency', 'KHR')) === 'USD' ? 'USD' : 'KHR';
    }

    /** Riel -> USD, 2 decimals, never below 1 cent. */
    public static function khrToUsd(float $khr, ?float $rate = null): float
    {
        return max(0.01, round($khr / ($rate ?? self::rate()), 2));
    }

    /** What to charge for a Riel amount in the chosen currency. */
    public static function charge(float $khr, string $currency, ?float $rate = null): float
    {
        return $currency === 'USD' ? self::khrToUsd($khr, $rate) : round($khr);
    }

    public static function publicConfig(): array
    {
        return ['exchange_rate' => self::rate(), 'default_currency' => self::defaultCurrency()];
    }
}
