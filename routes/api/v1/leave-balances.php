<?php

use App\Http\Controllers\Api\V1\LeaveBalanceController;
use Illuminate\Support\Facades\Route;

// Quản lý số dư phép (2026-10-15) — xem LeaveBalanceService.
// employee.scope: Trưởng phòng chỉ xem số dư phép nhân viên phòng mình (+ phòng con).
Route::middleware(['auth:api', 'employee.scope'])->prefix('leave-balances')->group(function (): void {
    // "/me" khai TRƯỚC {employee}.
    Route::get('/me', [LeaveBalanceController::class, 'mine'])->middleware('permission:leave.view_own');
    Route::get('/{employee}', [LeaveBalanceController::class, 'show'])->whereNumber('employee')->middleware('permission:leave.view_all');
    Route::post('/{employee}/adjust', [LeaveBalanceController::class, 'adjust'])->whereNumber('employee')->middleware('permission:leave.balance_adjust');
});
