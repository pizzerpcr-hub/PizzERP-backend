<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private const ADMINISTRATOR_ROLE = 'ADMINISTRADOR';

    private const ALLOWED_ROLES = [
        self::ADMINISTRATOR_ROLE,
        'CAJA',
        'COCINA',
        'TI',
    ];

    private const ALLOWED_STATUSES = [
        'ACTIVO',
        'INACTIVO',
    ];

    private const PUBLIC_COLUMNS = [
        'id_usuario',
        'nombre_completo',
        'nombre_usuario',
        'rol',
        'estado',
    ];

    public function store(Request $request): JsonResponse
    {
        $administrator = $this->authenticatedAdministrator($request);

        $request->merge([
            'nombre_completo' => trim(
                (string) $request->input('nombre_completo')
            ),
            'nombre_usuario' => mb_strtoupper(trim(
                (string) $request->input('nombre_usuario')
            )),
            'rol' => mb_strtoupper(trim(
                (string) $request->input('rol')
            )),
            'estado' => mb_strtoupper(trim(
                (string) $request->input('estado', 'ACTIVO')
            )),
        ]);

        $data = $request->validate([
            'nombre_completo' => [
                'required',
                'string',
                'max:100',
            ],
            'nombre_usuario' => [
                'bail',
                'required',
                'string',
                'max:50',
                'unique:usuarios,nombre_usuario',
            ],
            'contrasena' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->numbers(),
            ],
            'rol' => [
                'required',
                Rule::in(self::ALLOWED_ROLES),
            ],
            'estado' => [
                'required',
                Rule::in(self::ALLOWED_STATUSES),
            ],
        ], $this->validationMessages());

        $user = DB::transaction(function () use (
            $administrator,
            $data
        ): User {
            $user = User::create([
                'nombre_completo' => $data['nombre_completo'],
                'nombre_usuario' => $data['nombre_usuario'],
                'contrasena_hash' => $data['contrasena'],
                'rol' => $data['rol'],
                'estado' => $data['estado'],
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => null,
            ]);

            $this->recordAudit(
                $administrator,
                "Usuario {$user->nombre_usuario} (ID {$user->id_usuario}) creado.",
                'Se creó una cuenta de usuario.'
            );

            return $user;
        });

        return response()->json([
            'message' => 'Usuario creado exitosamente.',
            'usuario' => $this->userPayload($user),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authenticatedAdministrator($request);

        $users = User::query()
            ->select(self::PUBLIC_COLUMNS)
            ->orderBy('nombre_completo')
            ->get();

        return response()->json([
            'usuarios' => $users->map(
                fn (User $user): array => $this->userPayload($user)
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $administrator = $this->authenticatedAdministrator($request);
        $userId = $request->route('user');

        $request->merge([
            'nombre_completo' => trim(
                (string) $request->input('nombre_completo')
            ),
            'nombre_usuario' => mb_strtoupper(trim(
                (string) $request->input('nombre_usuario')
            )),
            'rol' => mb_strtoupper(trim(
                (string) $request->input('rol')
            )),
        ]);

        $updatedUser = DB::transaction(function () use (
            $administrator,
            $request,
            $userId
        ): User {
            $activeAdministratorCount = null;

            if (
                $request->input('rol') !== self::ADMINISTRATOR_ROLE
                && in_array($request->input('rol'), self::ALLOWED_ROLES, true)
            ) {
                $activeAdministratorCount =
                    $this->lockAndCountActiveAdministrators();
            }

            $lockedUser = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            $usernameRules = ['bail', 'required', 'string', 'max:50'];

            if ($request->input('nombre_usuario') !== $lockedUser->nombre_usuario) {
                $usernameRules[] = Rule::unique('usuarios', 'nombre_usuario')
                    ->ignore($lockedUser->id_usuario, 'id_usuario');
            }

            $data = $request->validate([
                'nombre_completo' => [
                    'required',
                    'string',
                    'max:100',
                ],
                'nombre_usuario' => $usernameRules,
                'rol' => [
                    'required',
                    Rule::in(self::ALLOWED_ROLES),
                ],
                'contrasena' => [
                    'nullable',
                    'string',
                    Password::min(8)
                        ->letters()
                        ->numbers(),
                ],
                'estado' => ['prohibited'],
            ], $this->validationMessages());

            $passwordWasProvided = isset($data['contrasena'])
                && $data['contrasena'] !== '';

            if (
                $lockedUser->rol === self::ADMINISTRATOR_ROLE
                && $lockedUser->estado === 'ACTIVO'
                && $data['rol'] !== self::ADMINISTRATOR_ROLE
                && $activeAdministratorCount === 1
            ) {
                throw ValidationException::withMessages([
                    'rol' => 'No se puede cambiar el rol del último administrador activo.',
                ]);
            }

            $lockedUser->fill([
                'nombre_completo' => $data['nombre_completo'],
                'nombre_usuario' => $data['nombre_usuario'],
                'rol' => $data['rol'],
            ]);

            if ($passwordWasProvided) {
                $lockedUser->contrasena_hash = $data['contrasena'];
            }

            $modifiedFields = $this->modifiedUserFields(
                $lockedUser,
                $passwordWasProvided
            );

            $lockedUser->save();

            if ($modifiedFields !== []) {
                $this->recordAudit(
                    $administrator,
                    "Usuario {$lockedUser->nombre_usuario} (ID {$lockedUser->id_usuario}) actualizado.",
                    'Campos modificados: '.implode(', ', $modifiedFields).'.'
                );
            }

            return $lockedUser;
        });

        return response()->json([
            'message' => 'Usuario actualizado exitosamente.',
            'usuario' => $this->userPayload($updatedUser),
        ]);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $administrator = $this->authenticatedAdministrator($request);
        $userId = $request->route('user');

        $request->merge([
            'estado' => mb_strtoupper(trim(
                (string) $request->input('estado')
            )),
        ]);

        if (
            $request->input('estado') === 'INACTIVO'
            && ctype_digit((string) $userId)
            && ltrim((string) $userId, '0') === (string) $administrator->getKey()
        ) {
            throw ValidationException::withMessages([
                'estado' => 'No puede desactivar su propia cuenta.',
            ]);
        }

        $updatedUser = DB::transaction(function () use (
            $administrator,
            $request,
            $userId
        ): User {
            $activeAdministratorCount = null;

            if ($request->input('estado') === 'INACTIVO') {
                $activeAdministratorCount =
                    $this->lockAndCountActiveAdministrators();
            }

            $lockedUser = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            $data = $request->validate([
                'estado' => [
                    'required',
                    Rule::in(self::ALLOWED_STATUSES),
                ],
            ], $this->validationMessages());

            if (
                $data['estado'] === 'INACTIVO'
                && $administrator->is($lockedUser)
            ) {
                throw ValidationException::withMessages([
                    'estado' => 'No puede desactivar su propia cuenta.',
                ]);
            }

            if (
                $data['estado'] === 'INACTIVO'
                && $lockedUser->rol === self::ADMINISTRATOR_ROLE
                && $lockedUser->estado === 'ACTIVO'
                && $activeAdministratorCount === 1
            ) {
                throw ValidationException::withMessages([
                    'estado' => 'No se puede desactivar al último administrador activo.',
                ]);
            }

            $previousStatus = $lockedUser->estado;
            $lockedUser->estado = $data['estado'];

            if (
                $previousStatus === 'INACTIVO'
                && $data['estado'] === 'ACTIVO'
            ) {
                $lockedUser->intentos_fallidos = 0;
                $lockedUser->bloqueado_hasta = null;
            }

            $lockedUser->save();

            if ($previousStatus !== $lockedUser->estado) {
                $this->recordAudit(
                    $administrator,
                    "Estado del usuario {$lockedUser->nombre_usuario} (ID {$lockedUser->id_usuario}) actualizado.",
                    "Estado anterior: {$previousStatus}; estado nuevo: {$lockedUser->estado}."
                );
            }

            return $lockedUser;
        });

        return response()->json([
            'message' => 'Estado del usuario actualizado exitosamente.',
            'usuario' => $this->userPayload($updatedUser),
        ]);
    }

    private function authenticatedAdministrator(Request $request): User
    {
        $authenticatedUser = $request->user();

        if (
            ! $authenticatedUser instanceof User
            || mb_strtoupper($authenticatedUser->rol)
                !== self::ADMINISTRATOR_ROLE
        ) {
            abort(403, 'No tiene permiso para gestionar usuarios.');
        }

        return $authenticatedUser;
    }

    private function lockAndCountActiveAdministrators(): int
    {
        return User::query()
            ->where('rol', self::ADMINISTRATOR_ROLE)
            ->where('estado', 'ACTIVO')
            ->orderBy('id_usuario')
            ->lockForUpdate()
            ->get(['id_usuario'])
            ->count();
    }

    /**
     * @return list<string>
     */
    private function modifiedUserFields(
        User $user,
        bool $passwordWasProvided
    ): array {
        $fieldNames = [
            'nombre_completo' => 'nombre_completo',
            'nombre_usuario' => 'nombre_usuario',
            'rol' => 'rol',
        ];

        $modifiedFields = [];

        foreach ($fieldNames as $attribute => $fieldName) {
            if ($user->isDirty($attribute)) {
                $modifiedFields[] = $fieldName;
            }
        }

        if ($passwordWasProvided) {
            $modifiedFields[] = 'contrasena';
        }

        return $modifiedFields;
    }

    /**
     * @return array{id_usuario: mixed, nombre_completo: mixed, nombre_usuario: mixed, rol: mixed, estado: mixed}
     */
    private function userPayload(User $user): array
    {
        return [
            'id_usuario' => $user->id_usuario,
            'nombre_completo' => $user->nombre_completo,
            'nombre_usuario' => $user->nombre_usuario,
            'rol' => $user->rol,
            'estado' => $user->estado,
        ];
    }

    private function recordAudit(
        User $administrator,
        string $description,
        string $reason
    ): void {
        Bitacora::create([
            'id_usuario' => $administrator->id_usuario,
            'descripcion_movimiento' => $description,
            'tipo_movimiento' => 'GESTION_USUARIOS',
            'motivo' => $reason,
            'fecha' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function validationMessages(): array
    {
        return [
            'nombre_completo.required' => 'El nombre completo es obligatorio.',
            'nombre_completo.max' => 'El nombre completo no puede superar 100 caracteres.',
            'nombre_usuario.required' => 'El nombre de usuario es obligatorio.',
            'nombre_usuario.max' => 'El nombre de usuario no puede superar 50 caracteres.',
            'nombre_usuario.unique' => 'El nombre de usuario ya está registrado.',
            'contrasena.required' => 'La contraseña es obligatoria.',
            'contrasena.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'rol.required' => 'El rol es obligatorio.',
            'rol.in' => 'El rol seleccionado no es válido.',
            'estado.required' => 'El estado es obligatorio.',
            'estado.in' => 'El estado debe ser ACTIVO o INACTIVO.',
            'estado.prohibited' => 'El estado debe modificarse mediante su endpoint específico.',
        ];
    }
}
