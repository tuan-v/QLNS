<?php

use App\Http\Controllers\Api\V1\OnboardingController;
use Illuminate\Support\Facades\Route;

// Onboarding / Offboarding. Xem danh sách: onboarding.manage (mọi nhân viên) hoặc
// onboarding.team (nhân viên mình quản lý trực tiếp). Checklist của chính mình và
// đánh dấu việc: chỉ cần đăng nhập — ChecklistService kiểm tra ai được đánh dấu việc nào.
// Mẫu checklist, tạo/hủy checklist, thêm/xóa việc: onboarding.manage.
Route::middleware('auth:api')->prefix('onboarding')->group(function (): void {
    Route::get('/me', [OnboardingController::class, 'mine']);
    Route::get('/checklists/{checklist}', [OnboardingController::class, 'show'])->whereNumber('checklist');
    Route::put('/items/{item}', [OnboardingController::class, 'updateItem'])->whereNumber('item');

    Route::get('/checklists', [OnboardingController::class, 'index'])
        ->middleware('permission:onboarding.manage,onboarding.team');

    Route::middleware('permission:onboarding.manage')->group(function (): void {
        Route::post('/checklists', [OnboardingController::class, 'store']);
        Route::post('/checklists/{checklist}/cancel', [OnboardingController::class, 'cancel'])->whereNumber('checklist');
        Route::post('/checklists/{checklist}/items', [OnboardingController::class, 'addItem'])->whereNumber('checklist');
        Route::delete('/items/{item}', [OnboardingController::class, 'destroyItem'])->whereNumber('item');

        Route::get('/templates', [OnboardingController::class, 'templates']);
        Route::post('/templates', [OnboardingController::class, 'storeTemplate']);
        Route::put('/templates/{template}', [OnboardingController::class, 'updateTemplate'])->whereNumber('template');
        Route::delete('/templates/{template}', [OnboardingController::class, 'destroyTemplate'])->whereNumber('template');
    });
});
