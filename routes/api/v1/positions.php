<?php

use App\Http\Controllers\Api\V1\PositionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('positions')->group(function (): void {
    Route::get('/', [PositionController::class, 'index'])->middleware('permission:department.view');
    Route::post('/', [PositionController::class, 'store'])->middleware('permission:department.manage');
    Route::put('/{position}', [PositionController::class, 'update'])->middleware('permission:department.manage');
    Route::delete('/{position}', [PositionController::class, 'destroy'])->middleware('permission:department.manage');
});
