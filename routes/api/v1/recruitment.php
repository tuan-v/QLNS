<?php

use App\Http\Controllers\Api\V1\RecruitmentController;
use App\Http\Controllers\Api\V1\RecruitmentOfferController;
use Illuminate\Support\Facades\Route;

// Tuyển dụng. Xem: recruitment.manage hoặc recruitment.approve. Quản lý đợt tuyển,
// tải CV, hẹn phỏng vấn, ghi kết quả: recruitment.manage. Duyệt CV: recruitment.approve.
Route::middleware('auth:api')->prefix('recruitment')->group(function (): void {
    Route::middleware('permission:recruitment.manage,recruitment.approve')->group(function (): void {
        Route::get('/openings', [RecruitmentController::class, 'index']);
        Route::get('/openings/{opening}', [RecruitmentController::class, 'show'])->whereNumber('opening');
        Route::get('/candidates/{candidate}/cv', [RecruitmentController::class, 'downloadCv'])
            ->whereNumber('candidate')
            ->name('recruitment.candidates.cv');
    });

    Route::middleware('permission:recruitment.manage')->group(function (): void {
        Route::post('/openings', [RecruitmentController::class, 'store']);
        Route::put('/openings/{opening}', [RecruitmentController::class, 'update'])->whereNumber('opening');
        Route::post('/openings/{opening}/close', [RecruitmentController::class, 'close'])->whereNumber('opening');
        Route::post('/openings/{opening}/reopen', [RecruitmentController::class, 'reopen'])->whereNumber('opening');
        Route::delete('/openings/{opening}', [RecruitmentController::class, 'destroy'])->whereNumber('opening');
        Route::post('/openings/{opening}/candidates', [RecruitmentController::class, 'storeCandidate'])->whereNumber('opening');
        Route::get('/openings/{opening}/check-duplicate', [RecruitmentController::class, 'checkDuplicate'])->whereNumber('opening');
        Route::post('/candidates/bulk-interview', [RecruitmentController::class, 'bulkScheduleInterviews']);
        Route::post('/candidates/{candidate}/interview', [RecruitmentController::class, 'scheduleInterview'])->whereNumber('candidate');
        Route::post('/candidates/{candidate}/result', [RecruitmentController::class, 'recordResult'])->whereNumber('candidate');
        Route::delete('/candidates/{candidate}', [RecruitmentController::class, 'destroyCandidate'])->whereNumber('candidate');
        // Thư mời nhận việc: HR soạn / rút.
        Route::post('/candidates/{candidate}/offers', [RecruitmentOfferController::class, 'store'])->whereNumber('candidate');
        Route::post('/offers/{offer}/withdraw', [RecruitmentOfferController::class, 'withdraw'])->whereNumber('offer');
    });

    // Admin duyệt thư mời trước khi gửi ứng viên.
    Route::post('/offers/{offer}/review', [RecruitmentOfferController::class, 'review'])
        ->whereNumber('offer')
        ->middleware('permission:recruitment.approve');

    Route::post('/candidates/bulk-review', [RecruitmentController::class, 'bulkReview'])
        ->middleware('permission:recruitment.approve');
    Route::post('/candidates/{candidate}/review', [RecruitmentController::class, 'review'])
        ->whereNumber('candidate')
        ->middleware('permission:recruitment.approve');
});

// Trang công khai cho ứng viên (KHÔNG đăng nhập) — mã link trong email thư mời nhận việc.
// Giới hạn 20 lần/phút/IP để chống dò mã.
Route::prefix('public/offers')->middleware('throttle:20,1')->group(function (): void {
    Route::get('/{token}', [RecruitmentOfferController::class, 'showPublic'])->where('token', '[A-Za-z0-9]{48}');
    Route::post('/{token}/respond', [RecruitmentOfferController::class, 'respondPublic'])->where('token', '[A-Za-z0-9]{48}');
});
