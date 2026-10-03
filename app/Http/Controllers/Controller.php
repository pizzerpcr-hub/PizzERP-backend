<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Models\User;
use App\Services\ManagementAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function listResponse(Request $request, Builder $query, string $key, ?callable $transform = null, ?callable $search = null): JsonResponse
    {
        if (! $request->has('page')) {
            $items = $query->get();

            return response()->json([$key => $transform ? $items->map($transform) : $items]);
        }

        $data = $request->validate([
            'page' => ['required', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        if ($search && filled($data['search'] ?? null)) {
            $search($query, trim($data['search']));
        }
        $page = (int) $data['page'];
        $paginator = $query->paginate(10, ['*'], 'page', $page);
        $items = $paginator->getCollection();

        return response()->json([
            $key => $transform ? $items->map($transform) : $items,
            'paginacion' => [
                'pagina' => $paginator->currentPage(),
                'totalPaginas' => $paginator->lastPage(),
                'totalElementos' => $paginator->total(),
                'inicio' => $paginator->firstItem() ?? 0,
                'fin' => $paginator->lastItem() ?? 0,
            ],
        ]);
    }

    protected function authorizeModule(Request $request, string $module, ?User $actor = null): User
    {
        $requestedStatus = $request->input('estado');
        $status = is_string($requestedStatus) ? mb_strtoupper(trim($requestedStatus)) : null;
        $action = match ($request->route()->getActionMethod()) {
            'index', 'show' => 'ver',
            'store' => 'crear',
            'destroy' => 'eliminar',
            'updateStatus' => $status === 'INACTIVO' ? 'eliminar' : 'editar',
            'update' => 'editar',
            default => abort(403, 'Operación no autorizada.'),
        };
        $user = $actor ?? $request->user();
        if (! $user instanceof User || ! $user->hasModulePermission($module, $action)) {
            abort(403, 'No tiene permiso para gestionar '.$module.'.');
        }
        $affected = $request->route('categoria') ?? $request->route('producto') ?? $request->route('ingrediente');
        if ($request->route()->getActionMethod() === 'update'
            && $status === 'INACTIVO'
            && $affected?->estado !== 'INACTIVO'
            && ! $user->hasModulePermission($module, 'eliminar')) {
            abort(403, 'No tiene permiso para desactivar '.$module.'.');
        }

        return $user;
    }

    /** @return array{User, Collection<int, Rol>} */
    protected function lockRolesAndAuthorize(Request $request, string $module): array
    {
        $roles = ManagementAccess::lockRoles();
        $actor = User::query()->findOrFail($request->user()->getKey());
        $actor->setRelation('assignedRole', $roles->firstWhere('nombre', $actor->rol));
        $this->authorizeModule($request, $module, $actor);

        return [$actor, $roles];
    }
}
