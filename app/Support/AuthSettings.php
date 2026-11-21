<?php

namespace App\Support;

use App\Models\Setting;

/** Login/Register settings (B3.1) with safe defaults when a row is missing. */
class AuthSettings
{
    public static function registrationEnabled(): bool
    {
        return (bool) Setting::get('registration_enabled', true);
    }

    public static function registrationRequiresApproval(): bool
    {
        return (bool) Setting::get('registration_requires_approval', true);
    }

    public static function activationEnabled(): bool
    {
        return (bool) Setting::get('activation_enabled', true);
    }

    public static function sessionMemberDays(): int
    {
        return max(1, (int) Setting::get('session_member_days', 30));
    }

    public static function sessionStaffHours(): int
    {
        return max(1, (int) Setting::get('session_staff_hours', 8));
    }

    public static function idleLockMinutes(): int
    {
        return max(0, (int) Setting::get('idle_lock_minutes', 15));
    }

    /** Public values the Login page and the staff screen lock need. */
    public static function publicConfig(): array
    {
        return [
            'registration_enabled' => self::registrationEnabled(),
            'activation_enabled'   => self::activationEnabled(),
            'idle_lock_minutes'    => self::idleLockMinutes(),
        ];
    }
}
