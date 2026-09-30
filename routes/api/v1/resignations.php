<?php

use App\Http\Controllers\Api\V1\ResignationRequestController;
use Illuminate\Support\Facades\Route;

// Đơn xin nghỉ việc (2026-09-29) — xem App\Services\ResignationService.
Route::middleware('auth:api')->prefix('resignations')->group(function (): void {
    Route::post('/', [ResignationRequestController::class, 'store'])->middleware('permission:resignation.request');
    // "/me" khai TRƯỚC các route {id}, cùng quy ước mọi route "/me" khác.
    Route::get('/me', [ResignationRequestController::class, 'mine'])->middleware('permission:resignation.request');
    Route::get('/policy', [ResignationRequestController::class, 'policy'])->middleware('permission:resignation.request');
    Route::post('/{resignationRequest}/cancel', [ResignationRequestController::class, 'cancel'])->middleware('permission:resignation.request');
    Route::get('/', [ResignationRequestController::class, 'index'])->middleware('permission:resignation.approve');
    Route::get('/{id}', [ResignationRequestController::class, 'show'])->whereNumber('id')->middleware('permission:resignation.approve');
    Route::put('/{id}/decide', [ResignationRequestController::class, 'decide'])->whereNumber('id')->middleware('permission:resignation.approve');
});
