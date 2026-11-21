<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\User;
use App\Services\Payments\BakongGateway;
use Illuminate\Console\Command;

/** Daily: warn admins before the Bakong developer token expires (it lasts about 90 days). */
class CheckBakongToken extends Command
{
    protected $signature = 'payments:check-bakong-token';

    protected $description = 'Warn admins when the Bakong token is about to expire';

    public function handle(): int
    {
        if (config('payment.driver') !== 'bakong') {
            $this->info('PAYMENT_DRIVER is not bakong - nothing to check.');
            return self::SUCCESS;
        }

        $days = BakongGateway::tokenDaysLeft((string) config('payment.bakong.token'));

        if ($days === null) {
            $this->warn('Could not read the Bakong token expiry.');
            return self::SUCCESS;
        }

        $this->info("Bakong token expires in {$days} days.");

        if ($days <= 10) {
            User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->each(function (User $admin) use ($days) {
                Notification::create([
                    'user_id' => $admin->id,
                    'title'   => __('payments.TOKEN_EXPIRING_TITLE'),
                    'message' => __('payments.TOKEN_EXPIRING_BODY', ['days' => max(0, $days)]),
                    'type'    => 'system',
                    'link'    => '/settings/system',
                    'is_read' => false,
                    'sent_at' => now(),
                ]);
            });
        }

        return self::SUCCESS;
    }
}
