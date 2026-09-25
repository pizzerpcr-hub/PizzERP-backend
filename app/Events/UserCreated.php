<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $usuario
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('usuarios'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.created';
    }
}