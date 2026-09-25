<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            ! $user instanceof User
            || mb_strtoupper(trim((string) $user->estado)) !== 'ACTIVO'
        ) {
            return response()->json([
                'message' => 'El usuario se encuentra inactivo.',
            ], 403);
        }

        return $next($request);
    }
}
