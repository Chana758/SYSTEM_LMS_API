<?php

namespace Database\Seeders\Demo;

use App\Models\ChatHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoChatSeeder extends Seeder
{
    public function run(): void
    {
        $this->guardDatabase();

        DB::transaction(function () {

            $domain = config('demo.email_domain');

            $user = User::where('email', "member@{$domain}")
                ->where('is_demo', true)
                ->first();

            if (! $user) {
                throw new \RuntimeException(
                    'Demo Member account not found.'
                );
            }

            $conversations = [
                [
                    'message' => 'How can I borrow a book?',
                    'response' => 'You can search for a book, open its details, and use the reservation or borrowing feature depending on its availability.',
                ],
                [
                    'message' => 'How can I check my borrowed books?',
                    'response' => 'Open your borrowing history to view borrowed books, due dates, and transaction statuses.',
                ],
                [
                    'message' => 'How much is the overdue fine?',
                    'response' => 'The demo library uses a sample overdue fine of 500 per day. Please check the library rules for the actual policy.',
                ],
            ];

            foreach ($conversations as $item) {

                $chat = ChatHistory::firstOrNew([
                    'user_id' => $user->id,
                    'message' => $item['message'],
                ]);

                $chat->fill([
                    'response' => $item['response'],
                ]);

                $chat->save();
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
                'Demo Chat Seeder: database guard failed.'
            );
        }
    }
}
