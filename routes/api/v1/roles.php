<?php

use App\Http\Controllers\Api\V1\RoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'permission:employee.update'])->prefix('roles')->group(function (): void {
    Route::get('/', [RoleController::class, 'index']);
});
