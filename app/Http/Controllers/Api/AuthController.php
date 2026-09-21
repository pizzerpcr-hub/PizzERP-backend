<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 5;

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = DB::transaction(function () use ($data): array {
            $username = mb_strtolower($data['username']);

            $user = User::query()
                ->whereRaw('LOWER(nombre_usuario) = ?', [$username])
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return [
                    'status' => 'invalid_credentials',
                ];
            }

            if (
                $user->bloqueado_hasta !== null
                && $user->bloqueado_hasta->isFuture()
            ) {
                $this->recordAudit(
                    $user,
                    'Intento de acceso mientras la cuenta estaba bloqueada.',
                    'AUTENTICACION',
                    'La cuenta permanece bloqueada temporalmente.'
                );

                return [
                    'status' => 'blocked',
                    'blocked_until' => $user->bloqueado_hasta,
                ];
            }

            if (
                $user->bloqueado_hasta !== null
                && $user->bloqueado_hasta->isPast()
            ) {
                $user->intentos_fallidos = 0;
                $user->bloqueado_hasta = null;
            }

            if (! Hash::check($data['password'], $user->contrasena_hash)) {
                $user->intentos_fallidos++;

                if (
                    $user->intentos_fallidos
                    >= self::MAX_FAILED_ATTEMPTS
                ) {
                    $user->intentos_fallidos = self::MAX_FAILED_ATTEMPTS;
                    $user->bloqueado_hasta = now()
                        ->addMinutes(self::LOCK_MINUTES);

                    $user->save();

                    $this->recordAudit(
                        $user,
                        'Cuenta bloqueada por intentos fallidos.',
                        'AUTENTICACION',
                        'Se alcanzó el máximo de 5 intentos fallidos.'
                    );

                    return [
                        'status' => 'blocked',
                        'blocked_until' => $user->bloqueado_hasta,
                    ];
                }

                $user->save();

                $this->recordAudit(
                    $user,
                    'Intento de inicio de sesión fallido.',
                    'AUTENTICACION',
                    'La contraseña ingresada es incorrecta.'
                );

                return [
                    'status' => 'invalid_credentials',
                    'remaining_attempts' => self::MAX_FAILED_ATTEMPTS
                        - $user->intentos_fallidos,
                ];
            }

            if (mb_strtoupper($user->estado) !== 'ACTIVO') {
                $this->recordAudit(
                    $user,
                    'Intento de acceso de un usuario inactivo.',
                    'AUTENTICACION',
                    'El estado del usuario no permite iniciar sesión.'
                );

                return [
                    'status' => 'inactive',
                ];
            }

            $user->intentos_fallidos = 0;
            $user->bloqueado_hasta = null;
            $user->save();

            $this->recordAudit(
                $user,
                'Inicio de sesión exitoso.',
                'AUTENTICACION',
                'Las credenciales fueron verificadas correctamente.'
            );

            return [
                'status' => 'authenticated',
                'user' => $user,
            ];
        });

        if ($result['status'] === 'invalid_credentials') {
            return response()->json([
                'message' => 'Usuario o contraseña incorrectos.',
                'intentos_restantes' =>
                    $result['remaining_attempts'] ?? null,
            ], 401);
        }

        if ($result['status'] === 'blocked') {
            return response()->json([
                'message' => 'La cuenta está bloqueada temporalmente.',
                'bloqueado_hasta' =>
                    $result['blocked_until']->toIso8601String(),
                'minutos_bloqueo' => self::LOCK_MINUTES,
            ], 423);
        }

        if ($result['status'] === 'inactive') {
            return response()->json([
                'message' => 'El usuario se encuentra inactivo.',
            ], 403);
        }

        $user = $result['user'];

        Auth::guard('web')->login(
            $user,
            $request->boolean('remember')
        );

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'usuario' => [
                'id_usuario' => $user->id_usuario,
                'nombre_completo' => $user->nombre_completo,
                'nombre_usuario' => $user->nombre_usuario,
                'rol' => $user->rol,
                'estado' => $user->estado,
            ],
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'usuario' => [
                'id_usuario' => $user->id_usuario,
                'nombre_completo' => $user->nombre_completo,
                'nombre_usuario' => $user->nombre_usuario,
                'rol' => $user->rol,
                'estado' => $user->estado,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    private function recordAudit(
        User $user,
        string $description,
        string $type,
        string $reason
    ): void {
        Bitacora::create([
            'id_usuario' => $user->id_usuario,
            'descripcion_movimiento' => $description,
            'tipo_movimiento' => $type,
            'motivo' => $reason,
            'fecha' => now(),
        ]);
    }
}