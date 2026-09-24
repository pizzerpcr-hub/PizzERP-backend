<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Bitacora;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 5;

    private const MAX_IP_FAILED_ATTEMPTS = 5;

    private const IP_LIMIT_SECONDS = 300;

    public function login(LoginRequest $request): JsonResponse
    {
        $ipLimitKey = 'login:ip:'.hash('sha256', (string) $request->ip());

        try {
            return Cache::store(config('cache.limiter'))
                ->lock($ipLimitKey.':lock', 30)
                ->block(10, fn (): JsonResponse => $this->attemptLogin($request, $ipLimitKey));
        } catch (LockTimeoutException) {
            return $this->failedLoginResponse();
        }
    }

    private function attemptLogin(LoginRequest $request, string $ipLimitKey): JsonResponse
    {
        if (RateLimiter::tooManyAttempts($ipLimitKey, self::MAX_IP_FAILED_ATTEMPTS)) {
            return $this->failedLoginResponse();
        }

        $data = $request->validated();
        $username = mb_strtolower(trim($data['username']));

        $user = User::query()
            ->whereRaw('LOWER(nombre_usuario) = ?', [$username])
            ->first();

        if (! $user) {
            return $this->failedLoginResponse($ipLimitKey);
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

            return $this->failedLoginResponse($ipLimitKey);
        }

        if (! Hash::check($data['password'], $user->contrasena_hash)) {
            $this->registerFailedAttempt($user);

            return $this->failedLoginResponse($ipLimitKey);
        }

        if (mb_strtoupper($user->estado) !== 'ACTIVO') {
            $this->recordAudit(
                $user,
                'Intento de acceso de un usuario inactivo.',
                'AUTENTICACION',
                'El estado del usuario no permite iniciar sesión.'
            );

            return $this->failedLoginResponse($ipLimitKey);
        }

        if (
            $user->intentos_fallidos !== 0
            || $user->bloqueado_hasta !== null
        ) {
            $user->forceFill([
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => null,
            ])->save();
        }

        $this->recordAudit(
            $user,
            'Inicio de sesión exitoso.',
            'AUTENTICACION',
            'Las credenciales fueron verificadas correctamente.'
        );

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

    private function registerFailedAttempt(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedUser->bloqueado_hasta !== null
                && $lockedUser->bloqueado_hasta->isFuture()
            ) {
                $this->recordAudit(
                    $lockedUser,
                    'Intento de acceso mientras la cuenta estaba bloqueada.',
                    'AUTENTICACION',
                    'La cuenta permanece bloqueada temporalmente.'
                );

                return;
            }

            if (
                $lockedUser->bloqueado_hasta !== null
                && $lockedUser->bloqueado_hasta->isPast()
            ) {
                $lockedUser->intentos_fallidos = 0;
                $lockedUser->bloqueado_hasta = null;
            }

            $lockedUser->intentos_fallidos++;

            if (
                $lockedUser->intentos_fallidos
                >= self::MAX_FAILED_ATTEMPTS
            ) {
                $lockedUser->intentos_fallidos =
                    self::MAX_FAILED_ATTEMPTS;

                $lockedUser->bloqueado_hasta = now()
                    ->addMinutes(self::LOCK_MINUTES);

                $lockedUser->save();

                $this->recordAudit(
                    $lockedUser,
                    'Cuenta bloqueada por intentos fallidos.',
                    'AUTENTICACION',
                    'Se alcanzó el máximo de 5 intentos fallidos.'
                );

                return;
            }

            $lockedUser->save();

            $this->recordAudit(
                $lockedUser,
                'Intento de inicio de sesión fallido.',
                'AUTENTICACION',
                'La contraseña ingresada es incorrecta.'
            );

        });
    }

    private function failedLoginResponse(?string $ipLimitKey = null): JsonResponse
    {
        if ($ipLimitKey !== null) {
            RateLimiter::hit($ipLimitKey, self::IP_LIMIT_SECONDS);
        }

        return response()->json([
            'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
        ], 401);
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
