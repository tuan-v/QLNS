<?php

use App\Http\Controllers\Api\V1\WorkShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('work-shifts')->group(function (): void {
    // "/default" phải khai TRƯỚC "/{workShift}" — cùng lý do mọi route cụ
    // thể khác trong dự án (vd "/me" trước "/{employee}").
    Route::get('/default', [WorkShiftController::class, 'showDefault'])->middleware('permission:shift.view');
    // Cũng cho phép attendance.check (2026-09-23) — nhân viên KHÔNG có
    // shift.view (không thấy trang "Ca làm việc") vẫn cần đọc danh sách ca
    // đang hoạt động để chọn khi "Xin làm ngoài lịch" (mục 17, loại
    // extra_shift), y hệt cách attendance.check đã cho phép họ gửi yêu cầu
    // điều chỉnh công nói chung.
    Route::get('/', [WorkShiftController::class, 'index'])->middleware('permission:shift.view,attendance.check');
    Route::post('/', [WorkShiftController::class, 'store'])->middleware('permission:shift.manage');
    Route::put('/{workShift}', [WorkShiftController::class, 'update'])->middleware('permission:shift.manage');
    Route::delete('/{workShift}', [WorkShiftController::class, 'destroy'])->middleware('permission:shift.manage');
});
