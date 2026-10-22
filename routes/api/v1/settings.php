<?php

use App\Http\Controllers\Api\V1\SystemSettingController;
use Illuminate\Support\Facades\Route;

// Cài đặt hệ thống — cùng quyền với trang /cai-dat-he-thong (shift.manage: Admin, HR).
Route::middleware(['auth:api', 'permission:shift.manage'])->prefix('settings')->group(function (): void {
    Route::get('/office-location', [SystemSettingController::class, 'officeLocation']);
    Route::put('/office-location', [SystemSettingController::class, 'updateOfficeLocation']);
});
