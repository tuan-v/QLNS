<?php

use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

// Mỗi user chỉ thấy thông báo của chính mình (lọc theo user_id ở Service/
// Repository) — không cần permission riêng ngoài đã đăng nhập, giống các
// endpoint "/me..." khác trong dự án.
Route::middleware(['auth:api'])->prefix('notifications')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index']);
    Route::patch('/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/{notification}/read', [NotificationController::class, 'markRead']);
});

// Xem TOÀN BỘ thông báo trong công ty (mọi user, không chỉ của mình) — gate
// riêng "notification.view_all", mặc định chỉ Admin có (xem PermissionSeeder/
// RolePermissionSeeder). Đặt TRƯỚC nhóm route "/{notification}/read" phía
// trên chỉ để dễ đọc — không bắt buộc vì khác hẳn method (GET) và số đoạn
// đường dẫn, Laravel không nhầm 2 route này với nhau.
Route::middleware(['auth:api', 'permission:notification.view_all'])->get('notifications/all', [NotificationController::class, 'all']);
