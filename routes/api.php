<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'message' => 'QLNS API is running',
        ]);
    });

    require __DIR__.'/api/v1/auth.php';
    require __DIR__.'/api/v1/departments.php';
    require __DIR__.'/api/v1/employees.php';
    require __DIR__.'/api/v1/attendances.php';
    require __DIR__.'/api/v1/leave-requests.php';
    require __DIR__.'/api/v1/payrolls.php';
    require __DIR__.'/api/v1/reports.php';
    require __DIR__.'/api/v1/notifications.php';
});
