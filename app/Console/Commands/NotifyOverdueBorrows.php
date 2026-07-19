<?php

namespace App\Console\Commands;

use App\Models\BorrowTransaction;
use App\Models\Notification;
use Illuminate\Console\Command;

class NotifyOverdueBorrows extends Command
{
    protected $signature = 'notifications:overdue';
    protected $description = 'Send overdue reminder notifications to members with overdue books';

    public function handle(): int
    {
        $overdue = BorrowTransaction::with(['member.user', 'bookCopy.book'])
            ->where('status', 'borrowed')
            ->where('due_date', '<', now())
            ->get();

        $sent = 0;
        foreach ($overdue as $borrow) {
            $alreadyNotifiedToday = Notification::where('user_id', $borrow->member->user_id)
                ->where('type', 'overdue')
                ->where('message', 'like', "%{$borrow->bookCopy->book->title}%")
                ->whereDate('created_at', now()->toDateString())
                ->exists();

            if ($alreadyNotifiedToday) {
                continue;
            }

            Notification::create([
                'user_id' => $borrow->member->user_id,
                'title'   => 'Overdue book reminder',
                'message' => "\"{$borrow->bookCopy->book->title}\" was due on " . $borrow->due_date->format('M d, Y') . '. Please return it as soon as possible.',
                'type'    => 'overdue',
                'link'    => '/my-borrows',
                'is_read' => false,
                'sent_at' => now(),
            ]);
            $sent++;
        }

        $this->info("Sent {$sent} overdue reminder(s).");
        return self::SUCCESS;
    }
}