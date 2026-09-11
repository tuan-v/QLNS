<?php

use App\Http\Controllers\Api\V1\LeaveTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->prefix('leave-types')->group(function (): void {
    Route::get('/', [LeaveTypeController::class, 'index']);
});
