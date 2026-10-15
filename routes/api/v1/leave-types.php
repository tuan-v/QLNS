<?php

use App\Http\Controllers\Api\V1\LeaveBalanceController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('leave-types')->group(function (): void {
    Route::get('/', [LeaveTypeController::class, 'index']);
    // Chính sách chuyển phép (2026-10-15) — chỉ Admin.
    Route::put('/{leaveType}/policy', [LeaveBalanceController::class, 'updatePolicy'])->middleware('permission:leave.manage');
});
