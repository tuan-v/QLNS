<?php

use App\Http\Controllers\Api\V1\HolidayController;
use Illuminate\Support\Facades\Route;

// Xem lịch nghỉ lễ: mọi người đăng nhập. Quản lý (thêm/xóa, sinh tự động, quy tắc,
// gửi thông báo): holiday.manage. Các route tĩnh khai TRƯỚC route có tham số.
Route::middleware('auth:api')->prefix('holidays')->group(function (): void {
    Route::get('/', [HolidayController::class, 'index']);
    Route::get('/convert', [HolidayController::class, 'convert']);

    Route::middleware('permission:holiday.manage')->group(function (): void {
        Route::post('/', [HolidayController::class, 'store']);
        Route::post('/generate', [HolidayController::class, 'generate']);
        Route::get('/rules', [HolidayController::class, 'rules']);
        Route::post('/rules', [HolidayController::class, 'storeRule']);
        Route::put('/rules/{holidayRule}', [HolidayController::class, 'updateRule']);
        Route::delete('/rules/{holidayRule}', [HolidayController::class, 'destroyRule']);
        Route::get('/groups/{groupCode}', [HolidayController::class, 'showGroup']);
        Route::post('/groups/{groupCode}/confirm', [HolidayController::class, 'confirmGroup']);
        Route::post('/groups/{groupCode}/apply', [HolidayController::class, 'applyChanges']);
        Route::post('/groups/{groupCode}/intern-paid', [HolidayController::class, 'setInternPaid']);
        Route::post('/groups/{groupCode}/days', [HolidayController::class, 'addDay']);
        Route::post('/groups/{groupCode}/swaps', [HolidayController::class, 'addSwap']);
        Route::post('/groups/{groupCode}/notify', [HolidayController::class, 'notifyGroup']);
        Route::delete('/days/{holiday}', [HolidayController::class, 'removeDay']);
        Route::delete('/groups/{groupCode}', [HolidayController::class, 'destroyGroup']);
    });
});
