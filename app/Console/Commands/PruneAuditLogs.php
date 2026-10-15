<?php

namespace App\Console\Commands;

use App\Services\LogService;
use Illuminate\Console\Command;

// Xóa nhật ký hoạt động cũ hơn config('audit.retention_months') tháng (mặc định 24 —
// người dùng chọn 2026-10-15). Chạy hằng ngày.
class PruneAuditLogs extends Command
{
    protected $signature = 'logs:prune';

    protected $description = 'Xóa nhật ký hoạt động quá thời hạn lưu trữ';

    public function handle(LogService $logService): int
    {
        $this->info('Đã xóa '.$logService->prune().' dòng nhật ký cũ.');

        return self::SUCCESS;
    }
}
