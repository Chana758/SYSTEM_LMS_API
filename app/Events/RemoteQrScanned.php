<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RemoteQrScanned implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $qrToken,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("remote-login.{$this->sessionId}")];
    }

    public function broadcastAs(): string
    {
        return 'RemoteQrScanned';
    }
}