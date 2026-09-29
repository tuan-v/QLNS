<?php

use App\Http\Controllers\Api\V1\SearchController;
use Illuminate\Support\Facades\Route;

// Không cần permission riêng ở route — SearchService tự lọc TỪNG loại kết
// quả theo đúng quyền của user (employee.view, ...), giống DashboardController
// (xem app/Services/SearchService.php).
Route::middleware(['auth:api'])->get('search', [SearchController::class, 'index']);
