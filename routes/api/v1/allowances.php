<?php

use App\Http\Controllers\Api\V1\AllowanceController;
use App\Http\Controllers\Api\V1\AllowanceRequestController;
use Illuminate\Support\Facades\Route;

// Phụ cấp & trợ cấp (Kế hoạch Ngày 48). Xem: payroll.view_all; đặt mức / sửa danh mục: payroll.manage.
// Đổi mức của nhân viên: người có allowance.approve (Admin) áp dụng ngay, còn lại thành đề xuất chờ duyệt.
Route::middleware('auth:api')->group(function () {
    // Nhân viên xem phụ cấp của chính mình — khai TRƯỚC /employees/{employee}/allowances.
    Route::get('/employees/me/allowances', [AllowanceController::class, 'mine']);

    Route::middleware('permission:payroll.view_all')->group(function () {
        Route::get('/allowances', [AllowanceController::class, 'index']);
        Route::get('/allowances/employees', [AllowanceController::class, 'employees']);
        Route::get('/employees/{employee}/allowances', [AllowanceController::class, 'forEmployee']);
        Route::get('/allowance-requests', [AllowanceRequestController::class, 'index']);
    });

    Route::middleware('permission:allowance.approve')->group(function () {
        Route::post('/allowance-requests/approve', [AllowanceRequestController::class, 'approve']);
        Route::post('/allowance-requests/reject', [AllowanceRequestController::class, 'reject']);
    });
    // Rút lại: người đề xuất hoặc Admin (kiểm trong AllowanceChangeService::cancel()).
    Route::delete('/allowance-requests/{allowanceRequest}', [AllowanceRequestController::class, 'destroy'])
        ->whereNumber('allowanceRequest')
        ->middleware('permission:payroll.manage');

    Route::middleware('permission:payroll.manage')->group(function () {
        Route::post('/allowances', [AllowanceController::class, 'store']);
        Route::post('/allowances/bulk-set', [AllowanceController::class, 'bulkSet']);
        Route::put('/allowances/{salaryComponent}', [AllowanceController::class, 'update']);
        Route::delete('/allowances/{salaryComponent}', [AllowanceController::class, 'destroy']);
        Route::post('/allowances/{salaryComponent}/apply-suggested', [AllowanceController::class, 'applyToAll']);
        Route::post('/employees/{employee}/allowances', [AllowanceController::class, 'set']);
        Route::post('/employees/{employee}/allowances/apply-suggested', [AllowanceController::class, 'applySuggested']);
    });
});
