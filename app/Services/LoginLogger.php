<?php

namespace App\Services;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records every login, failed login, logout and card refusal.
 * Never throws: a logging problem must not stop anyone from logging in.
 */
class LoginLogger
{
    public static function record(
        string $event,
        ?User $user = null,
        bool $success = true,
        ?string $reason = null,
        ?string $identifier = null,
        ?string $device = null,
    ): void {
        try {
            $request = request();

            LoginLog::create([
                'user_id'    => $user?->id,
                'identifier' => $identifier !== null ? Str::limit($identifier, 180, '') : null,
                'event'      => $event,
                'success'    => $success,
                'reason'     => $reason,
                'ip'         => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'device'     => $device,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Login log not saved: ' . $e->getMessage());
        }
    }
}
