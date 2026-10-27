<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class VisitorChatAvailable implements ShouldBroadcastNow
{
    public function __construct(private int $tenant, private string $sessionId, private array $envelope) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('organizations.'.$this->tenant.'.visitors.'.$this->sessionId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.visitor_changed.v1';
    }

    public function broadcastWith(): array
    {
        return $this->envelope;
    }

    public function broadcastConnections(): array
    {
        return ['reverb'];
    }
}
