<?php

use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeContractController;
use App\Http\Controllers\Api\V1\EmployeeDocumentController;
use App\Http\Controllers\Api\V1\EmployeeTransferController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('employees')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])->middleware('permission:employee.view');
    Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:employee.create');
    Route::get('/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employee.view');
    Route::put('/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employee.update');
    Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employee.delete');
    Route::post('/{employee}/avatar', [EmployeeController::class, 'uploadAvatar'])->middleware('permission:employee.update');
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
});
