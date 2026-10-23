<?php

use App\Http\Controllers\Api\V1\BackupController;
use Illuminate\Support\Facades\Route;

// Sao lưu dữ liệu — chỉ người có system.backup (Admin). Khôi phục không có API, chỉ bằng lệnh backup:restore.
Route::middleware(['auth:api', 'permission:system.backup'])->prefix('backups')->group(function (): void {
    Route::get('/', [BackupController::class, 'index']);
    Route::post('/', [BackupController::class, 'store']);
    Route::get('/{file}/download', [BackupController::class, 'download']);
});
