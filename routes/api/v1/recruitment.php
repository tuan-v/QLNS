<?php

use App\Http\Controllers\Api\V1\InterviewScorecardController;
use App\Http\Controllers\Api\V1\RecruitmentController;
use App\Http\Controllers\Api\V1\RecruitmentInterviewController;
use App\Http\Controllers\Api\V1\RecruitmentOfferController;
use App\Http\Controllers\Api\V1\RecruitmentReportController;
use Illuminate\Support\Facades\Route;

// Tuyển dụng. Xem: recruitment.manage hoặc recruitment.approve. Quản lý đợt tuyển,
// tải CV, hẹn phỏng vấn, ghi kết quả: recruitment.manage. Duyệt CV: recruitment.approve.
Route::middleware('auth:api')->prefix('recruitment')->group(function (): void {
    Route::middleware('permission:recruitment.manage,recruitment.approve')->group(function (): void {
        Route::get('/openings', [RecruitmentController::class, 'index']);
        Route::get('/openings/{opening}', [RecruitmentController::class, 'show'])->whereNumber('opening');
        // Trang "Lịch phỏng vấn": các buổi phỏng vấn trong 1 tháng + số liệu.
        Route::get('/interviews', [RecruitmentInterviewController::class, 'index']);
        Route::get('/interviewers', [RecruitmentInterviewController::class, 'interviewers']);
        // Lịch sử ứng tuyển của 1 người theo email/SĐT (mọi đợt tuyển).
        Route::get('/candidates/history', [RecruitmentController::class, 'candidateHistory']);
        // Bộ câu hỏi phỏng vấn (xem) + phiếu chấm (HR + Admin cùng chấm).
        Route::get('/question-sets', [InterviewScorecardController::class, 'sets']);
        Route::get('/position-question-sets', [InterviewScorecardController::class, 'positionDefaults']);
        // Báo cáo tuyển dụng: phễu, thời gian xử lý, theo nguồn / vị trí.
        Route::get('/report', [RecruitmentReportController::class, 'index']);
        // Sửa offer đang chờ duyệt: HR (người soạn) hoặc Admin ngay lúc xem duyệt.
        Route::put('/offers/{offer}', [RecruitmentOfferController::class, 'update'])->whereNumber('offer');
        Route::get('/interviews/{interview}/scorecard', [InterviewScorecardController::class, 'show'])->whereNumber('interview');
        Route::post('/interviews/{interview}/scorecard', [InterviewScorecardController::class, 'start'])->whereNumber('interview');
        Route::put('/scorecards/{scorecard}', [InterviewScorecardController::class, 'save'])->whereNumber('scorecard');
        Route::post('/scorecards/{scorecard}/submit', [InterviewScorecardController::class, 'submit'])->whereNumber('scorecard');
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
        // Đợt tuyển chung: sửa vị trí ứng tuyển / hình thức mong muốn.
        Route::put('/candidates/{candidate}/application', [RecruitmentController::class, 'updateApplication'])->whereNumber('candidate');
        Route::delete('/candidates/{candidate}', [RecruitmentController::class, 'destroyCandidate'])->whereNumber('candidate');
        // HR ghi nhận ứng viên đồng ý / xin đổi lịch / không tham gia phỏng vấn (báo Admin).
        Route::post('/interviews/{interview}/confirmation', [RecruitmentInterviewController::class, 'confirm'])->whereNumber('interview');
        // Thư mời nhận việc: HR soạn / rút.
        Route::get('/offer-defaults', [RecruitmentOfferController::class, 'defaults']);
        Route::post('/candidates/{candidate}/offers', [RecruitmentOfferController::class, 'store'])->whereNumber('candidate');
        Route::post('/offers/{offer}/withdraw', [RecruitmentOfferController::class, 'withdraw'])->whereNumber('offer');
        // Ứng viên báo chấp nhận / từ chối qua điện thoại, email -> HR ghi nhận (Admin được thông báo).
        Route::post('/offers/{offer}/response', [RecruitmentOfferController::class, 'recordResponse'])->whereNumber('offer');
    });

    // Admin soạn bộ câu hỏi + gắn cho đợt tuyển.
    Route::middleware('permission:recruitment.approve')->group(function (): void {
        Route::post('/question-sets', [InterviewScorecardController::class, 'storeSet']);
        Route::put('/question-sets/{set}', [InterviewScorecardController::class, 'updateSet'])->whereNumber('set');
        Route::delete('/question-sets/{set}', [InterviewScorecardController::class, 'destroySet'])->whereNumber('set');
        Route::put('/openings/{opening}/question-sets', [InterviewScorecardController::class, 'assignToOpening'])->whereNumber('opening');
        // Bộ câu hỏi mặc định của chức vụ (đợt tuyển mới của chức vụ tự chép sang).
        Route::put('/candidates/{candidate}/question-sets', [InterviewScorecardController::class, 'assignToCandidate'])->whereNumber('candidate');
        Route::put('/positions/{position}/question-sets', [InterviewScorecardController::class, 'assignToPosition'])->whereNumber('position');
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
