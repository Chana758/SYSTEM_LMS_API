<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
    \App\Models\Setting::class => \App\Policies\SettingPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // This is an API-only app: there is no web route named "password.reset",
        // so Laravel's default reset email would crash with "Route [password.reset] not defined".
        // Build the link to the Vue page instead: /reset-password/{token}?email=...
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim(config('app.frontend_url'), '/')
                . '/reset-password/' . $token
                . '?email=' . urlencode($user->getEmailForPasswordReset());
        });
    }
}