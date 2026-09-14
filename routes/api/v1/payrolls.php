<?php

use App\Http\Controllers\Api\V1\PayrollController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('payrolls')->group(function (): void {
    Route::get('/', [PayrollController::class, 'index'])->middleware('permission:payroll.view_all');
    Route::get('/{payroll}', [PayrollController::class, 'show'])->middleware('permission:payroll.view_all');
    Route::post('/generate', [PayrollController::class, 'generate'])->middleware('permission:payroll.manage');
    Route::post('/{payroll}/close', [PayrollController::class, 'close'])->middleware('permission:payroll.manage');
    Route::post('/{payroll}/mark-paid', [PayrollController::class, 'markAsPaid'])->middleware('permission:payroll.manage');
});
