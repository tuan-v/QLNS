<?php

use App\Http\Controllers\Api\V1\EmployeeAccountController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeContractController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeShiftAssignmentController;
use App\Http\Controllers\Api\V1\EmployeeTransferController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('employees')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])->middleware('permission:employee.view');
    Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:employee.create');
    // Phải khai TRƯỚC "/{employee}" — Laravel khớp route theo thứ tự đăng ký,
    // nếu để sau thì "stats"/"me" bị chính "{employee}" nuốt mất (hiểu nhầm thành id).
    Route::get('/stats', [EmployeeController::class, 'stats'])->middleware('permission:employee.view');
    // Không gắn permission:employee.view — xem hồ sơ CHÍNH MÌNH không phụ
    // thuộc mã quyền xem người khác, xem EmployeeController::me().
    Route::get('/me', [EmployeeController::class, 'me']);
    Route::get('/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employee.view');
    Route::put('/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employee.update');
    Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employee.delete');
    Route::post('/{employee}/avatar', [EmployeeController::class, 'uploadAvatar'])->middleware('permission:employee.update');
    Route::post('/{employee}/account', [EmployeeAccountController::class, 'store'])->middleware('permission:employee.update');
    Route::get('/{employee}/contracts', [EmployeeContractController::class, 'index'])->middleware('permission:employee.view');
    Route::post('/{employee}/contracts', [EmployeeContractController::class, 'store'])->middleware('permission:employee.update');
    Route::get('/{employee}/contracts/{contract}/download', [EmployeeContractController::class, 'download'])
        ->middleware('permission:employee.view')
        ->name('employees.contracts.download');
    Route::get('/{employee}/documents', [EmployeeDocumentController::class, 'index'])->middleware('permission:employee.view');
    Route::post('/{employee}/documents', [EmployeeDocumentController::class, 'store'])->middleware('permission:employee.update');
    Route::delete('/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware('permission:employee.update');
    Route::get('/{employee}/documents/{document}/download', [EmployeeDocumentController::class, 'download'])
        ->middleware('permission:employee.view')
        ->name('employees.documents.download');
    Route::get('/{employee}/transfers', [EmployeeTransferController::class, 'index'])->middleware('permission:employee.view');
    Route::post('/{employee}/transfers', [EmployeeTransferController::class, 'store'])->middleware('permission:employee.update');
    Route::get('/{employee}/transfers/{transfer}/download', [EmployeeTransferController::class, 'download'])
        ->middleware('permission:employee.view')
        ->name('employees.transfers.download');
    Route::get('/{employee}/shift-assignments', [EmployeeShiftAssignmentController::class, 'index'])->middleware('permission:shift.view');
    Route::post('/{employee}/shift-assignments', [EmployeeShiftAssignmentController::class, 'store'])->middleware('permission:shift.manage');
    Route::put('/{employee}/shift-assignments/{shiftAssignment}', [EmployeeShiftAssignmentController::class, 'update'])->middleware('permission:shift.manage');
    Route::delete('/{employee}/shift-assignments/{shiftAssignment}', [EmployeeShiftAssignmentController::class, 'destroy'])->middleware('permission:shift.manage');
});
