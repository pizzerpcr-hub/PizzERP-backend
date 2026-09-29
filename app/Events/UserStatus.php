<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;


class UserStatus implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, SerializesModels;

    public string $connection = 'deferred';

    public function __construct(
        public array $usuario
    ) {
        $this->usuario = array_intersect_key($usuario, array_flip([
            'id_usuario', 'nombre_completo', 'nombre_usuario', 'rol', 'estado',
        ]));
    }

    public function broadcastWith(): array
    {
        return ['usuario' => $this->usuario];
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('usuario.'.$this->usuario['id_usuario']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.status-changed';
    }
}
