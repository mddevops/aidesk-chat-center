<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ChatMessageAvailable implements ShouldBroadcastNow
{
    public function __construct(private int $tenant, private array $operators, private array $envelope) {}

    public function broadcastOn(): array
    {
        return array_map(fn ($id) => new PrivateChannel('organizations.'.$this->tenant.'.operators.'.$id), $this->operators);
    }

    public function broadcastAs(): string
    {
        return 'chat.message_available.v1';
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
