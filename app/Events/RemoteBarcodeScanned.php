<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RemoteBarcodeScanned implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $barcode,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("remote-scan.{$this->sessionId}")];
    }

    public function broadcastAs(): string
    {
        return 'RemoteBarcodeScanned';
    }
}