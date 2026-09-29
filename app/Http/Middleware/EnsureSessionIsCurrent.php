<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsCurrent
{
    public const IDLE_MINUTES = 30;

    public const REMEMBER_DAYS = 14;

    public const GRANT_COOKIE = 'pizzerp_remember_grant';

    private const SESSION_POLICY = 'pizzerp_session_policy';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $guard = Auth::guard('web');

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $policy = $request->session()->get(self::SESSION_POLICY);

        if (! is_array($policy) && $guard->viaRemember()) {
            $issuedAt = $this->validGrantIssuedAt($request, $user);

            if ($issuedAt !== null) {
                $policy = [
                    'user_id' => (string) $user->getKey(),
                    'mode' => 'remember',
                    'expires_at' => $issuedAt + self::REMEMBER_DAYS * 86400,
                ];
                $request->session()->put(self::SESSION_POLICY, $policy);
            }
        }

        // Stateless tokens do not carry a browser session. A session or recaller
        // cookie without a policy must not resurrect an older login.
        if (! is_array($policy)) {
            if ($request->cookie(config('session.cookie')) || $guard->viaRemember()) {
                return $this->expired($request, $next);
            }

            return $next($request);
        }

        $now = now()->timestamp;
        $matchesUser = ($policy['user_id'] ?? null) === (string) $user->getKey();
        $remembered = ($policy['mode'] ?? null) === 'remember';
        $normal = ($policy['mode'] ?? null) === 'normal';
        $validUntil = $remembered
            ? ($policy['expires_at'] ?? null)
            : (($policy['last_activity'] ?? null) + self::IDLE_MINUTES * 60);

        if (! $matchesUser || (! $remembered && ! $normal)
            || ! is_int($validUntil) || $now >= $validUntil) {
            return $this->expired($request, $next);
        }

        if ($normal) {
            $policy['last_activity'] = $now;
            $request->session()->put(self::SESSION_POLICY, $policy);
        }

        return $next($request);
    }

    public static function startSession(Request $request, User $user, bool $remember): void
    {
        $now = now()->timestamp;
        $request->session()->put(self::SESSION_POLICY, $remember
            ? [
                'user_id' => (string) $user->getKey(),
                'mode' => 'remember',
                'expires_at' => $now + self::REMEMBER_DAYS * 86400,
            ]
            : [
                'user_id' => (string) $user->getKey(),
                'mode' => 'normal',
                'last_activity' => $now,
            ]);
    }

    public static function grantCookie(User $user): \Symfony\Component\HttpFoundation\Cookie
    {
        $issuedAt = now()->timestamp;
        $value = (string) $user->getKey().'|'.$issuedAt;
        $signature = hash_hmac('sha256', $value, config('app.key'));

        return cookie(
            self::GRANT_COOKIE,
            $value.'|'.$signature,
            self::REMEMBER_DAYS * 1440,
            config('session.path'),
            config('session.domain'),
            config('session.secure'),
            true,
            false,
            config('session.same_site')
        );
    }

    private function validGrantIssuedAt(Request $request, User $user): ?int
    {
        $value = $request->cookie(self::GRANT_COOKIE);

        if (! is_string($value) || ! preg_match('/^(\d+)\|(\d+)\|([a-f0-9]{64})$/D', $value, $matches)) {
            return null;
        }

        $issuedAt = (int) $matches[2];
        $now = now()->timestamp;

        if ($matches[1] !== (string) $user->getKey() || $issuedAt > $now
            || $now >= $issuedAt + self::REMEMBER_DAYS * 86400
            || ! hash_equals(hash_hmac('sha256', $matches[1].'|'.$matches[2], config('app.key')), $matches[3])) {
            return null;
        }

        return $issuedAt;
    }

    private function expired(Request $request, Closure $next): Response
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(self::GRANT_COOKIE, config('session.path'), config('session.domain')));

        if ($request->routeIs('auth.logout')) {
            return $next($request);
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }
}
