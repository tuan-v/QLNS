<?php

use App\Http\Controllers\Api\V1\EmployeeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('employees')->group(function (): void {
    Route::get('/', [EmployeeController::class, 'index'])->middleware('permission:employee.view');
    Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:employee.create');
    Route::get('/{employee}', [EmployeeController::class, 'show'])->middleware('permission:employee.view');
    Route::put('/{employee}', [EmployeeController::class, 'update'])->middleware('permission:employee.update');
    Route::delete('/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:employee.delete');
});
