<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, String ...$permission): Response
    {
        $user = $request->user();
        $userPermission = $user->cachedPermissionCodes();
        $phanGiao = array_intersect($userPermission, $permission);
        if (empty($phanGiao)) {
            return response()->json(["message" => "khong co quyen nao khop"], 403);
        }
        return $next($request);

    }
}
