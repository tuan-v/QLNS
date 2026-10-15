<?php

use App\Http\Controllers\Api\V1\LogController;
use Illuminate\Support\Facades\Route;

// Nhật ký hoạt động (tài liệu yêu cầu mục 9 "Audit Log") — chỉ Admin (system.audit_log).
Route::middleware(['auth:api', 'permission:system.audit_log'])->prefix('logs')->group(function (): void {
    Route::get('/', [LogController::class, 'index']);
    Route::get('/filters', [LogController::class, 'filters']);
    Route::get('/{log}', [LogController::class, 'show'])->whereNumber('log');
});
