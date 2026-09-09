<?php

use App\Http\Controllers\Api\V1\WorkShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('work-shifts')->group(function (): void {
    Route::get('/', [WorkShiftController::class, 'index'])->middleware('permission:shift.view');
    Route::post('/', [WorkShiftController::class, 'store'])->middleware('permission:shift.manage');
    Route::put('/{workShift}', [WorkShiftController::class, 'update'])->middleware('permission:shift.manage');
    Route::delete('/{workShift}', [WorkShiftController::class, 'destroy'])->middleware('permission:shift.manage');
});
