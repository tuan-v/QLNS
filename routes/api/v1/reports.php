<?php

use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

// Module Báo cáo (Kế hoạch Ngày 51-58, 2026-10-16) — xem ReportService.
// Phạm vi phòng ban (Manager chỉ phòng mình) kiểm trong ReportService; chi phí lương thêm
// quyền payroll.view_all (Manager không xem lương).
Route::middleware(['auth:api', 'permission:report.view'])->prefix('reports')->group(function (): void {
    Route::get('/filters', [ReportController::class, 'filters']);
    Route::get('/workforce', [ReportController::class, 'workforce']);
    Route::get('/attendance', [ReportController::class, 'attendance']);
    Route::get('/payroll-cost', [ReportController::class, 'payrollCost'])->middleware('permission:payroll.view_all');
    Route::get('/export', [ReportController::class, 'export']);
});
