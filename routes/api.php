<?php

use Illuminate\Support\Facades\Route;

// throttle:api = giới hạn chung cho mọi API (config/rate_limits.php).
Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'message' => 'QLNS API is running',
        ]);
    });

    require __DIR__ . '/api/v1/auth.php';
    require __DIR__ . '/api/v1/dashboard.php';
    require __DIR__ . '/api/v1/departments.php';
    require __DIR__ . '/api/v1/positions.php';
    require __DIR__ . '/api/v1/addresses.php';
    require __DIR__ . '/api/v1/employees.php';
    require __DIR__ . '/api/v1/roles.php';
    require __DIR__ . '/api/v1/permissions.php';
    require __DIR__ . '/api/v1/work-shifts.php';
    require __DIR__ . '/api/v1/attendances.php';
    require __DIR__ . '/api/v1/leave-requests.php';
    require __DIR__ . '/api/v1/leave-types.php';
    require __DIR__ . '/api/v1/payrolls.php';
    require __DIR__ . '/api/v1/reports.php';
    require __DIR__ . '/api/v1/notifications.php';
    require __DIR__ . '/api/v1/search.php';
    require __DIR__ . '/api/v1/resignations.php';
    require __DIR__ . '/api/v1/holidays.php';
    require __DIR__ . '/api/v1/recruitment.php';
    require __DIR__ . '/api/v1/onboarding.php';
});
