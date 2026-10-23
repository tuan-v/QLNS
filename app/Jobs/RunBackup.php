<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// "Sao lưu ngay" của Admin chạy nền qua queue: không treo request web vài chục giây, và chạy cùng tiến trình với lịch
// tự động (container queue/scheduler) nên file sao lưu luôn cùng một chủ sở hữu. Xong / lỗi đều có thông báo.
class RunBackup implements ShouldQueue
{
    use Queueable;

    // BackupService::run() tự bắt lỗi và báo — không thử lại để tránh báo lỗi nhiều lần.
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $requestedBy)
    {
    }

    public function handle(BackupService $backupService): void
    {
        $backupService->run(User::find($this->requestedBy));
    }
}
