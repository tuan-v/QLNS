<?php

use App\Http\Controllers\Api\V1\RoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'permission:employee.update'])->prefix('roles')->group(function (): void {
    Route::get('/', [RoleController::class, 'index']);
});

// Quản lý Role đầy đủ (Thêm/Sửa/Xóa/gán quyền) — riêng biệt với route đọc ở
// trên, gate "rbac.manage" (mặc định chỉ Admin có, xem PermissionSeeder).
Route::middleware(['auth:api', 'permission:rbac.manage'])->prefix('roles')->group(function (): void {
    Route::post('/', [RoleController::class, 'store']);
    Route::get('/{role}', [RoleController::class, 'show']);
    Route::put('/{role}', [RoleController::class, 'update']);
    Route::delete('/{role}', [RoleController::class, 'destroy']);
    Route::put('/{role}/permissions', [RoleController::class, 'updatePermissions']);
});
