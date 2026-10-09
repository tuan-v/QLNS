<?php

namespace App\Console\Commands;

use App\Services\RecruitmentOfferService;
use Illuminate\Console\Command;

// Thư mời nhận việc đã gửi mà qua hạn trả lời -> "hết hạn", trả lại suất cho đợt tuyển.
// Trang trả lời của ứng viên cũng tự kiểm tra hạn; job này để danh sách nội bộ luôn đúng.
class ExpireRecruitmentOffers extends Command
{
    protected $signature = 'recruitment:expire-offers';

    protected $description = 'Đánh dấu hết hạn các thư mời nhận việc quá hạn trả lời';

    public function handle(RecruitmentOfferService $offerService): int
    {
        $this->info('Đã chuyển hết hạn '.$offerService->expireOverdue().' thư mời.');

        return self::SUCCESS;
    }
}
