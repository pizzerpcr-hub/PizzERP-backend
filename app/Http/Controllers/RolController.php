<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Rol;
use App\Models\User;
use App\Services\ManagementAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RolController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeModule($request, 'roles');

        return response()->json(['roles' => Rol::query()->withCount('usuarios')->orderBy('nombre')->get()]);
    }

    public function show(Request $request, string $role): JsonResponse
    {
        $this->authorizeModule($request, 'roles');

        return response()->json(['rol' => Rol::query()->withCount('usuarios')->findOrFail($role)]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->authorizeModule($request, 'roles');
        $this->normalize($request);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:30', Rule::unique('roles', 'nombre')],
            ...Rol::permissionRules(),
            'estado' => ['sometimes', 'required', Rule::in(['ACTIVO', 'INACTIVO'])],
            'es_sistema' => ['prohibited'],
        ]);
        $this->authorizeDelegation($actor, $data['permisos']);
        $role = DB::transaction(function () use ($request, $data): Rol {
            [$actor] = $this->lockRolesAndAuthorize($request, 'roles');
            $this->authorizeDelegation($actor, $data['permisos']);
            $role = Rol::create([...$data, 'estado' => $data['estado'] ?? 'ACTIVO']);
            $this->audit($actor, $role, 'creado');

            return $role;
        });

        return response()->json(['message' => 'Rol creado exitosamente.', 'rol' => $role->loadCount('usuarios')], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        $this->authorizeModule($request, 'roles');
        $this->normalize($request);
        $updated = DB::transaction(function () use ($request, $role): Rol {
            [$actor, $roles] = $this->lockRolesAndAuthorize($request, 'roles');
            $locked = $roles->find($role) ?? abort(404);
            $wasManager = $locked->grantsManagement();
            $this->authorizeMutableRole($actor, $locked);
            $rules = [
                'nombre' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('roles', 'nombre')->ignore($locked->id_rol, 'id_rol')],
                'estado' => ['prohibited'], 'es_sistema' => ['prohibited'],
            ];
            if ($request->exists('permisos')) {
                $rules = [...$rules, ...Rol::permissionRules()];
            }
            $data = $request->validate($rules);
            if (isset($data['permisos'])) {
                $this->authorizeDelegation($actor, $data['permisos']);
            }
            $locked->fill($data);
            if ($locked->isDirty()) {
                $locked->save();
                if ($wasManager && ! $locked->grantsManagement()) {
                    ManagementAccess::assertManagerRemains($roles, 'permisos');
                }
                $this->audit($actor, $locked, 'actualizado');
            }

            return $locked;
        });

        return response()->json(['message' => 'Rol actualizado exitosamente.', 'rol' => $updated->loadCount('usuarios')]);
    }

    public function updateStatus(Request $request, string $role): JsonResponse
    {
        $this->authorizeModule($request, 'roles');
        $this->normalize($request);
        $data = $request->validate(['estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])]]);
        $updated = DB::transaction(function () use ($request, $role, $data): Rol {
            [$actor, $roles] = $this->lockRolesAndAuthorize($request, 'roles');
            $locked = $roles->find($role) ?? abort(404);
            $wasManager = $locked->grantsManagement();
            $this->authorizeMutableRole($actor, $locked);
            if ($locked->estado !== $data['estado']) {
                $locked->estado = $data['estado'];
                $locked->save();
                if ($wasManager && ! $locked->grantsManagement()) {
                    ManagementAccess::assertManagerRemains($roles, 'estado');
                }
                $this->audit($actor, $locked, 'estado actualizado a '.$locked->estado);
            }

            return $locked;
        });

        return response()->json(['message' => 'Estado del rol actualizado exitosamente.', 'rol' => $updated->loadCount('usuarios')]);
    }

    private function normalize(Request $request): void
    {
        foreach (['nombre', 'estado'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => mb_strtoupper(trim($request->input($field)))]);
            }
        }
    }

    private function authorizeMutableRole(User $actor, Rol $role): void
    {
        $this->authorizeDelegation($actor, $role->permisos);
    }

    private function authorizeDelegation(User $actor, array $permissions): void
    {
        if (! $actor->mayDelegatePermissions($permissions)) {
            abort(403, 'No puede otorgar ni gestionar permisos que no posee.');
        }
    }

    private function audit(User $actor, Rol $role, string $action): void
    {
        Bitacora::create([
            'id_usuario' => $actor->id_usuario,
            'descripcion_movimiento' => "Rol {$role->nombre} (ID {$role->id_rol}) {$action}.",
            'tipo_movimiento' => 'GESTION_ROLES', 'motivo' => 'Gestión de roles y permisos.', 'fecha' => now(),
        ]);
    }
}
