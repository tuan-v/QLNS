<?php

use App\Http\Controllers\Api\V1\AttendanceLocationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('attendance-locations')->group(function (): void {
    Route::get('/', [AttendanceLocationController::class, 'index'])->middleware('permission:location.view');
    Route::post('/', [AttendanceLocationController::class, 'store'])->middleware('permission:location.manage');
    Route::put('/{attendanceLocation}', [AttendanceLocationController::class, 'update'])->middleware('permission:location.manage');
    Route::delete('/{attendanceLocation}', [AttendanceLocationController::class, 'destroy'])->middleware('permission:location.manage');
});
