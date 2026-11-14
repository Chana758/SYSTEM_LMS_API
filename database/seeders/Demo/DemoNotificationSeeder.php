<?php

namespace Database\Seeders\Demo;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoNotificationSeeder extends Seeder
{
    public function run(): void
    {
        $this->guardDatabase();

        DB::transaction(function () {

            $users = User::where('is_demo', true)->get();

            foreach ($users as $user) {

                $items = [
                    [
                        'key' => 'welcome',
                        'title' => 'Welcome to Demo Library!',
                        'message' => 'Welcome! You can explore books and manage your borrowing activities.',
                        'type' => 'system',
                        'is_read' => false,
                    ],
                    [
                        'key' => 'borrow',
                        'title' => 'Book Borrowed Successfully',
                        'message' => 'Your demo book borrowing transaction has been created successfully.',
                        'type' => 'system',
                        'is_read' => true,
                    ],
                    [
                        'key' => 'reminder',
                        'title' => 'Book Return Reminder',
                        'message' => 'Please check your borrowed books and return them before the due date.',
                        'type' => 'system',
                        'is_read' => false,
                    ],
                ];

                foreach ($items as $item) {

                    $marker = '[DEMO-NOTIFICATION:' . $item['key'] . ']';

                    $notification = Notification::firstOrNew([
                        'user_id' => $user->id,
                        'notes' => $marker,
                    ]);

                    $notification->fill([
                        'title' => $item['title'],
                        'message' => $item['message'],
                        'type' => $item['type'],
                        'link' => null,
                        'is_read' => $item['is_read'],
                        'read_at' => $item['is_read'] ? now()->subDay() : null,
                        'sent_at' => now(),
                        'notes' => $marker,
                    ]);

                    $notification->save();
                }
            }
        });
    }

    private function guardDatabase(): void
    {
        $expected = config('demo.database_name');
        $actual = DB::connection()->getDatabaseName();

        if (
            ! config('demo.enabled') ||
            ! app()->environment('production') ||
            ! $expected ||
            $actual !== $expected
        ) {
            throw new \RuntimeException(
                'Demo Notification Seeder: database guard failed.'
            );
        }
    }
}
