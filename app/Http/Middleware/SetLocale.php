<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    /**
     * Supported locales — keep in sync with frontend's languageStore.js
     * (which only ever sends 'en' or 'km').
     */
    private const SUPPORTED_LOCALES = ['en', 'km'];

    private const DEFAULT_LOCALE = 'km';

    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('X-Locale', self::DEFAULT_LOCALE);

        if (in_array($locale, self::SUPPORTED_LOCALES, true)) {
            App::setLocale($locale);
        } else {
            App::setLocale(self::DEFAULT_LOCALE);
        }

        return $next($request);
    }
}