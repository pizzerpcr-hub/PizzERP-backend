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

class AuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 5;

    private const BROWSER_COOKIE = 'pizzerp_login_browser';

    private const MAX_BROWSER_FAILED_ATTEMPTS = 5;

    private const BROWSER_LOCK_SECONDS = 300;

    public function login(LoginRequest $request): JsonResponse
    {
        $browserId = $this->browserId($request);
        $browserKey = 'login:browser:'.$browserId;

        try {
            $response = Cache::store(config('cache.limiter'))
                ->lock($browserKey.':lock', 30)
                ->block(10, fn (): JsonResponse => $this->attemptLogin($request, $browserKey));
        } catch (LockTimeoutException) {
            $response = $this->failedLoginResponse();
        }

        return $response->withCookie(cookie(
            self::BROWSER_COOKIE,
            $browserId.'.'.hash_hmac('sha256', $browserId, config('app.key')),
            60 * 24 * 365,
            '/',
            null,
            $request->isSecure() || config('session.secure') === true,
            true,
            false,
            'lax'
        ));
    }

    private function attemptLogin(LoginRequest $request, string $browserKey): JsonResponse
    {
        $remaining = Cache::store(config('cache.limiter'))->get($browserKey.':blocked_until');

        if (is_int($remaining) && $remaining > now()->timestamp) {
            return $this->tooManyAttemptsResponse($remaining);
        }

        $data = $request->validated();
        $username = mb_strtolower(trim($data['username']));

        $user = User::query()
            ->whereRaw('LOWER(nombre_usuario) = ?', [$username])
            ->first();

        if (! $user) {
            return $this->failedLoginResponse($browserKey);
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

            return $this->failedLoginResponse($browserKey);
        }

        if (! Hash::check($data['password'], $user->contrasena_hash)) {
            $this->registerFailedAttempt($user);

            return $this->failedLoginResponse($browserKey);
        }

        if (mb_strtoupper($user->estado) !== 'ACTIVO') {
            $this->recordAudit(
                $user,
                'Intento de acceso de un usuario inactivo.',
                'AUTENTICACION',
                'El estado del usuario no permite iniciar sesión.'
            );

            return $this->failedLoginResponse($browserKey);
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
        Cache::store(config('cache.limiter'))->forget($browserKey.':attempts');

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
        Auth::guard('web')->logoutCurrentDevice();

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

    /*
     * Respuesta genérica de credenciales inválidas.
     * Los fallos se acumulan para el navegador hasta un login correcto
     * o el vencimiento del bloqueo iniciado por el quinto fallo.
     */
    private function failedLoginResponse(?string $browserKey = null): JsonResponse
    {
        if ($browserKey !== null) {
            $cache = Cache::store(config('cache.limiter'));
            $attempts = (int) $cache->get($browserKey.':attempts', 0) + 1;

            if ($attempts >= self::MAX_BROWSER_FAILED_ATTEMPTS) {
                $cache->forget($browserKey.':attempts');
                $blockedUntil = now()->timestamp + self::BROWSER_LOCK_SECONDS;
                $cache->put($browserKey.':blocked_until', $blockedUntil, self::BROWSER_LOCK_SECONDS);

                return $this->tooManyAttemptsResponse($blockedUntil);
            }

            $cache->forever($browserKey.':attempts', $attempts);
        }

        return response()->json([
            'message' => "No fue posible iniciar sesión.\nVerifica tus credenciales.",
        ], 401);
    }

    /*
     * Respuesta cuando se excedió el límite de intentos por navegador.
     * No menciona si la cuenta existe, está bloqueada o inactiva:
     * el mensaje es igual para cualquier motivo de fallo previo.
     */
    private function tooManyAttemptsResponse(int $blockedUntil): JsonResponse
    {
        $segundosRestantes = max(1, $blockedUntil - now()->timestamp);

        return response()->json([
            'message' => "Se alcanzó el limite de intentos.\n",
            'retry_after' => $segundosRestantes,
        ], 429);
    }

    private function browserId(Request $request): string
    {
        $cookie = $request->cookie(self::BROWSER_COOKIE);

        if (is_string($cookie) && preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $cookie, $matches)) {
            if (hash_equals(hash_hmac('sha256', $matches[1], config('app.key')), $matches[2])) {
                return $matches[1];
            }
        }

        return bin2hex(random_bytes(32));
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
