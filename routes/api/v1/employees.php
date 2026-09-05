<?php

use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\EmployeeContractController;
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
});
