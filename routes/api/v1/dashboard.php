<?php

use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

// Không cần permission riêng — mọi user đăng nhập đều gọi được, nội dung tự
// đổi theo quyền của CHÍNH họ bên trong DashboardService::forUser().
Route::middleware(['auth:api'])->get('dashboard', [DashboardController::class, 'index']);
