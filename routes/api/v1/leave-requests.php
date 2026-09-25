<?php

use App\Http\Controllers\Api\V1\LeaveRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('leave-requests')->group(function (): void {
    Route::post('/', [LeaveRequestController::class, 'store'])->middleware('permission:leave.request');
    // "/me" phải khai TRƯỚC route dùng {param}, cùng lý do mọi route "/me"
    // khác trong dự án (routes/api/v1/employees.php, attendances.php...).
    Route::get('/me', [LeaveRequestController::class, 'mine'])->middleware('permission:leave.view_own');
    Route::get('/balances/me', [LeaveRequestController::class, 'balancesMine'])->middleware('permission:leave.view_own');
    // "/balances/{employee}" phải khai SAU "/balances/me" — cùng lý do thứ tự
    // route mọi nơi khác, không thì "me" bị hiểu nhầm thành 1 employee id.
    Route::get('/balances/{employee}', [LeaveRequestController::class, 'balances'])->middleware('permission:leave.view_all');
    Route::get('/', [LeaveRequestController::class, 'index'])->middleware('permission:leave.view_all');
    // Duyệt HÀNG LOẠT (2026-09-25) — khai TRƯỚC '/{leaveRequest}/decide', cùng
    // quy ước "route cụ thể trước route tham số" xuyên suốt file này (2 route
    // này không cùng số đoạn URL nên thật ra không tranh chấp, chỉ theo quy
    // ước cho dễ đọc).
    Route::put('/bulk-decide', [LeaveRequestController::class, 'bulkDecide'])->middleware('permission:leave.approve_manager,leave.approve_hr');
    Route::put('/{leaveRequest}/decide', [LeaveRequestController::class, 'decide'])->middleware('permission:leave.approve_manager,leave.approve_hr');
    // Không gắn permission riêng — download() tự kiểm tra "chính mình hoặc
    // leave.view_all" bên trong Controller, giống EmployeeDocumentController.
    Route::get('/{leaveRequest}/evidence', [LeaveRequestController::class, 'downloadEvidence']);
});
