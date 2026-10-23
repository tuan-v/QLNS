<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

// Khôi phục từ 1 bản sao lưu. CHỈ chạy bằng lệnh (không có nút trên web) để không ai bấm nhầm ghi đè dữ liệu.
//   php artisan backup:restore qlns-backup-2026-10-23_020000.zip              -> ghi đè CSDL đang dùng + file tải lên
//   php artisan backup:restore <file> --database=qlns_kiem_tra --skip-files   -> nạp vào CSDL khác để kiểm tra bản sao lưu
class RestoreBackup extends Command
{
    protected $signature = 'backup:restore
        {file : Tên bản sao lưu (trong thư mục sao lưu) hoặc đường dẫn tới file .zip}
        {--database= : Nạp vào CSDL khác thay vì CSDL đang dùng (tự tạo nếu chưa có)}
        {--skip-files : Không chép lại file tải lên}
        {--force : Không hỏi xác nhận}';

    protected $description = 'Khôi phục CSDL (và file tải lên) từ 1 bản sao lưu';

    public function handle(BackupService $backupService): int
    {
        $database = $this->option('database') ?: config('database.connections.'.config('database.default').'.database');
        $withFiles = ! $this->option('skip-files');

        $this->warn("Sẽ XÓA và nạp lại toàn bộ bảng của CSDL \"{$database}\" từ {$this->argument('file')}"
            .($withFiles ? ', đồng thời ghi đè file tải lên.' : '.'));
        $this->line('Nên bật bảo trì (php artisan down) trước khi khôi phục CSDL đang dùng.');

        if (! $this->option('force') && ! $this->confirm('Tiếp tục khôi phục?')) {
            $this->info('Đã hủy.');

            return self::SUCCESS;
        }

        $result = $backupService->restore($this->argument('file'), $this->option('database') ?: null, $withFiles);
        $this->info("Đã khôi phục: {$result['statements']} câu lệnh SQL, {$result['files']} file.");

        return self::SUCCESS;
    }
}
