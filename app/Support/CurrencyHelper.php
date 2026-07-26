<?php

namespace App\Support;

/**
 * Single source of truth for Riel (KHR) ↔ USD conversion on the backend.
 * Every fine amount (overdue/damaged/lost) must be converted through
 * this class before being stored in the Fine.amount column (USD).
 */
class CurrencyHelper
{
    // ⚠️ TODO: update to the real exchange rate
    public const KHR_TO_USD_RATE = 4100;

    public static function khrToUsd(float|int $khrAmount): float
    {
        return round($khrAmount / self::KHR_TO_USD_RATE, 2);
    }
}
