<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockDemoWrites
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** Public endpoints disabled on the demo deployment. */
    private const DEMO_DISABLED_PUBLIC = [
        'POST api/auth/register',
        'POST api/auth/forgot-password',
        'POST api/auth/reset-password',
    ];

    /** GET endpoints that still have side effects. */
    private const BLOCKED_GET = [
        'GET api/settings/backup/create',
    ];

    /** Harmless per-user writes any demo user may do. */
    private const ALLOWED_FOR_ALL_DEMO = [
        'POST api/auth/logout',
        'PUT api/auth/profile/preferences',
        'POST api/notifications/mark-all-read',
        'POST api/notifications/{notification}/read',
    ];

    /** Extra writes only for the demo librarian. */
    private const ALLOWED_FOR_DEMO_LIBRARIAN = [
        'POST api/borrows',
        'POST api/borrows/{borrow}/return',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->method() . ' ' . ($request->route()?->uri() ?? $request->path());

        if (config('demo.enabled') && in_array($key, self::DEMO_DISABLED_PUBLIC, true)) {
            return $this->deny('This action is disabled in the public demo.');
        }

        // Real user comes from the Sanctum token, never from the request body.
        $user = $request->user('sanctum');

        if (! $user || ! $user->is_demo) {
            return $next($request);
        }

        if (in_array($request->method(), self::SAFE_METHODS, true)
            && ! in_array($key, self::BLOCKED_GET, true)) {
            return $next($request);
        }

        $allowed = self::ALLOWED_FOR_ALL_DEMO;
        if ($user->role?->name === 'librarian') {
            $allowed = array_merge($allowed, self::ALLOWED_FOR_DEMO_LIBRARIAN);
        }

        if (in_array($key, $allowed, true)) {
            return $next($request);
        }

        return $this->deny('Demo accounts are read-only. This action is not available in demo mode.');
    }

    private function deny(string $message): Response
    {
        return response()->json(['code' => 'DEMO_RESTRICTED', 'message' => $message], 403);
    }
}