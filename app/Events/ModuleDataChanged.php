<?php

namespace App\Events;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;

class ModuleDataChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable, InteractsWithSockets;

    public const MODULES = ['usuarios', 'roles', 'categorias', 'productos', 'ingredientes', 'combos'];

    public const AFFECTED = [
        'usuarios' => ['usuarios', 'roles'],
        'roles' => ['roles', 'usuarios'],
        'categorias' => ['categorias', 'productos', 'combos'],
        'productos' => ['productos', 'categorias', 'combos'],
        'ingredientes' => ['ingredientes', 'productos', 'combos'],
        'combos' => ['combos'],
    ];

    public string $connection = 'deferred';

    public function __construct(public string $module, public string $action)
    {
        if (! in_array($module, self::MODULES, true)
            || ! in_array($action, ['created', 'updated', 'deleted', 'status'], true)) {
            throw new InvalidArgumentException('Invalid module notification.');
        }
        $this->dontBroadcastToCurrentUser();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        // Resolve recipients at delivery, not from a permission snapshot taken before commit.
        $roles = Rol::query()->where('estado', 'ACTIVO')->get(['nombre', 'permisos']);
        $eligible = $roles->filter(fn (Rol $role): bool => collect(self::AFFECTED[$this->module])
            ->contains(fn (string $module): bool => ($role->permisos[$module]['ver'] ?? false) === true));
        if ($eligible->isEmpty()) {
            return [];
        }
        $permissions = $eligible->keyBy('nombre');
        $users = User::query()->where('estado', 'ACTIVO')->whereIn('rol', $eligible->pluck('nombre'))
            ->get(['id_usuario', 'rol']);
        $channels = [];
        foreach ($users as $user) {
            foreach (self::AFFECTED[$this->module] as $module) {
                if (($permissions[$user->rol]->permisos[$module]['ver'] ?? false) === true) {
                    $channels[] = new PrivateChannel('crud.usuario.'.$user->getKey().'.'.$module);
                }
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'crud.changed';
    }

    /** @return array{modulo: string, accion: string} */
    public function broadcastWith(): array
    {
        return ['modulo' => $this->module, 'accion' => $this->action];
    }
}
