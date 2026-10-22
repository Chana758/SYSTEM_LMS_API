<?php

namespace App\Support;

/**
 * Single source of truth for Riel (KHR) to USD conversion.
 * Fine amounts (overdue/damaged/lost) must be converted here before
 * being stored in Fine.amount (USD).
 */
class CurrencyHelper
{
    // TODO: update to the real exchange rate
    public const KHR_TO_USD_RATE = 4100;

    /** Convert KHR to USD, rounded to 2 decimals. */
    public static function khrToUsd(float|int $khrAmount): float
    {
        return round($khrAmount / self::KHR_TO_USD_RATE, 2);
    }
}