<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Cache;

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
        $userPermission = Cache::remember("permission:{$user->id}", 60, function () use ($user) {
            return $user->permissionCodes();
        });
        $phanGiao = array_intersect($userPermission, $permission);
        if (empty($phanGiao)) {
            return response()->json(["message" => "khong co quyen nao khop"], 403);
        }
        return $next($request);

    }
}
