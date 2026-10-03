<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserAccessChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, SerializesModels;

    public string $connection = 'deferred';

    /** @param array<string, array<string, bool>> $permissions */
    public function __construct(
        public int $userId,
        public int $roleId,
        public string $roleName,
        public array $permissions,
        public int $revision,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('usuario.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'user.access-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'rol_id' => $this->roleId,
            'rol' => $this->roleName,
            'permisos' => $this->permissions,
            'revision' => $this->revision,
        ];
    }
}
