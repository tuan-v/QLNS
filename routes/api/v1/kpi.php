<?php

use App\Http\Controllers\Api\V1\KpiController;
use Illuminate\Support\Facades\Route;

// KPI tháng từ công việc dự án — phạm vi xem lọc trong KpiService (không gắn mã quyền ở route).
Route::middleware('auth:api')->prefix('kpi')->group(function () {
    Route::get('/filters', [KpiController::class, 'filters']);
    Route::get('/', [KpiController::class, 'index']);
    Route::get('/employees/{employee}', [KpiController::class, 'show']);
});
