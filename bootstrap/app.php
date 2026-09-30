<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['auth:api']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware xác thực dựng sẵn URL chuyển hướng bằng route('login') — route này
        // không tồn tại (đăng nhập là trang SPA) nên với API phải trả null để ra 401.
        $middleware->redirectGuestsTo(
            fn ($request) => $request->is('api/*') || $request->expectsJson() ? null : '/login',
        );
        $middleware->trustProxies(at: ['127.0.0.1', '::1', '172.19.0.0/16']);
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API luôn trả lỗi dạng JSON (kể cả client không gửi Accept: application/json) —
        // trước đây thiếu header đó thì lỗi xác thực bị chuyển hướng tới route 'login'
        // không tồn tại và trả 500 thay vì 401.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, Throwable $e) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
