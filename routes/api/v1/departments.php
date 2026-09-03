<?php

use App\Http\Controllers\Api\V1\DepartmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('departments')->group(function (): void {
    Route::get('/', [DepartmentController::class, 'index'])->middleware('permission:department.view');
    Route::post('/', [DepartmentController::class, 'store'])->middleware('permission:department.manage');
    Route::get('/tree', [DepartmentController::class, 'tree'])->middleware('permission:department.view');
    Route::put('/{department}', [DepartmentController::class, 'update'])->middleware('permission:department.manage');
    Route::delete('/{department}', [DepartmentController::class, 'destroy'])->middleware('permission:department.manage');
});
