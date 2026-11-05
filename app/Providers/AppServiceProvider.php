<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Refuse destructive artisan commands unless the DB name contains "demo" or "test".
        Event::listen(CommandStarting::class, function (CommandStarting $e) {
            $destructive = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'];
            if (! in_array($e->command, $destructive, true)) {
                return;
            }
            $conn = config('database.default');
            $db = strtolower((string) config("database.connections.{$conn}.database"));
            if (! str_contains($db, 'demo') && ! str_contains($db, 'test')) {
                fwrite(STDERR, "BLOCKED: '{$e->command}' refused on database '{$db}' (name must contain 'demo' or 'test').\n");
                exit(1);
            }
        });
    }
}