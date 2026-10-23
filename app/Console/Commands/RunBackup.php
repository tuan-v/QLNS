<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

// Sao lưu CSDL + file tải lên (chạy tự động hằng ngày, xem routes/console.php và config/backup.php).
class RunBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Sao lưu toàn bộ CSDL và file tải lên, xóa bản cũ quá số bản giữ lại';

    public function handle(BackupService $backupService): int
    {
        $backup = $backupService->run();

        if ($backup === null) {
            $this->error('Sao lưu thất bại — xem storage/logs, Admin đã được thông báo.');

            return self::FAILURE;
        }

        $this->info("Đã tạo {$backup['name']} (".BackupService::humanSize($backup['size']).') tại '.config('backup.path'));

        return self::SUCCESS;
    }
}
