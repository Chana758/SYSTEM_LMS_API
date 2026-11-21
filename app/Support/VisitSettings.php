<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/** Library hours, seats and kiosk session length, with safe defaults. */
class VisitSettings
{
    public static function openTime(): string
    {
        return self::time('library_open_time', '07:00');
    }

    public static function closeTime(): string
    {
        return self::time('library_close_time', '17:30');
    }

    public static function capacity(): int
    {
        return max(0, (int) Setting::get('library_capacity', 40));
    }

    public static function rescanSeconds(): int
    {
        return max(0, (int) Setting::get('visit_rescan_seconds', 60));
    }

    public static function kioskDays(): int
    {
        return max(1, (int) Setting::get('session_kiosk_days', 180));
    }

    public static function voiceEnabled(): bool
    {
        return (bool) Setting::get('kiosk_voice_enabled', true);
    }

    /** Say the person's name (browser text-to-speech, when the tablet has a Khmer voice). */
    public static function voiceName(): bool
    {
        return (bool) Setting::get('kiosk_voice_name', true);
    }

    public static function voiceVolume(): int
    {
        return min(100, max(0, (int) Setting::get('kiosk_voice_volume', 80)));
    }

    /** Closing time on the day of $date. */
    public static function closingOn(Carbon $date): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', self::closeTime()));

        return $date->copy()->setTime($h, $m);
    }

    public static function all(): array
    {
        return [
            'library_open_time'    => self::openTime(),
            'library_close_time'   => self::closeTime(),
            'library_capacity'     => self::capacity(),
            'visit_rescan_seconds' => self::rescanSeconds(),
            'session_kiosk_days'   => self::kioskDays(),
            'kiosk_voice_enabled'  => self::voiceEnabled(),
            'kiosk_voice_name'     => self::voiceName(),
            'kiosk_voice_volume'   => self::voiceVolume(),
        ];
    }

    private static function time(string $key, string $default): string
    {
        $value = (string) Setting::get($key, $default);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $default;
    }
}
