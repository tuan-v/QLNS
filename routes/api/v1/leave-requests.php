<?php

use App\Http\Controllers\Api\V1\LeaveRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('leave-requests')->group(function (): void {
    Route::post('/', [LeaveRequestController::class, 'store'])->middleware('permission:leave.request');
    // "/me" phải khai TRƯỚC route dùng {param}, cùng lý do mọi route "/me"
    // khác trong dự án (routes/api/v1/employees.php, attendances.php...).
    Route::get('/me', [LeaveRequestController::class, 'mine'])->middleware('permission:leave.view_own');
    Route::get('/', [LeaveRequestController::class, 'index'])->middleware('permission:leave.view_all');
    Route::put('/{leaveRequest}/decide', [LeaveRequestController::class, 'decide'])->middleware('permission:leave.approve_manager,leave.approve_hr');
});
