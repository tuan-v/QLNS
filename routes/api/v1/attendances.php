<?php

use App\Http\Controllers\Api\V1\AttendanceAdjustmentController;
use App\Http\Controllers\Api\V1\AttendanceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('attendances')->group(function (): void {
    Route::post('/check-in', [AttendanceController::class, 'checkIn'])->middleware('permission:attendance.check');
    Route::post('/check-out', [AttendanceController::class, 'checkOut'])->middleware('permission:attendance.check');
    // Phải khai TRƯỚC "/" (index) không xung đột vì không dùng {param}, nhưng
    // vẫn đặt các route cụ thể lên trước cho nhất quán với các file route khác.
    Route::get('/today', [AttendanceController::class, 'today'])->middleware('permission:attendance.check');
    Route::get('/me', [AttendanceController::class, 'mine'])->middleware('permission:attendance.view_own');
    // Báo cáo "Lịch sử chấm công" (mục 18) — "/history/me" phải khai TRƯỚC
    // "/history/{employee}", cùng lý do mọi route "/me" khác trong dự án.
    Route::get('/history/me', [AttendanceController::class, 'historyMine'])->middleware('permission:attendance.view_own');
    Route::get('/history/{employee}', [AttendanceController::class, 'history'])->middleware('permission:attendance.view_all');
    // Tổng hợp chấm công trong ngày (mục 16, thay màn "Duyệt chấm công" cũ) —
    // toàn công ty theo 1 ngày, cùng quyền xem như /history/{employee}.
    Route::get('/overview', [AttendanceController::class, 'overview'])->middleware('permission:attendance.view_all');
    Route::get('/', [AttendanceController::class, 'index'])->middleware('permission:attendance.view_all');

    // Điều chỉnh công — "/adjustments/me" phải khai TRƯỚC "/adjustments/{adjustment}",
    // cùng lý do "/me" phải khai trước "/{employee}" ở routes/api/v1/employees.php.
    Route::post('/adjustments', [AttendanceAdjustmentController::class, 'store'])->middleware('permission:attendance.check');
    Route::get('/adjustments/me', [AttendanceAdjustmentController::class, 'mine'])->middleware('permission:attendance.view_own');
    Route::get('/adjustments', [AttendanceAdjustmentController::class, 'index'])->middleware('permission:attendance.adjust');
    Route::put('/adjustments/{adjustment}', [AttendanceAdjustmentController::class, 'decide'])->middleware('permission:attendance.adjust');

    // Duyệt HÀNG LOẠT (2026-09-25) — phải khai TRƯỚC '/{attendance}/approval'
    // (dù whereNumber() đã đủ để tránh nuốt nhầm route chữ, vẫn theo đúng quy
    // ước "route cụ thể trước route tham số" xuyên suốt file này).
    Route::put('/bulk-approval', [AttendanceController::class, 'bulkDecideApproval'])
        ->middleware('permission:attendance.approve');

    // Duyệt chấm công (2026-09-21) — HR duyệt/từ chối từng bản ghi ca+ngày; chưa
    // duyệt thì không tính công/lương. whereNumber() để '/{attendance}/approval'
    // không bao giờ nuốt nhầm các route cố định ở trên (vd '/adjustments/...').
    Route::put('/{attendance}/approval', [AttendanceController::class, 'decideApproval'])
        ->whereNumber('attendance')
        ->middleware('permission:attendance.approve');
});
