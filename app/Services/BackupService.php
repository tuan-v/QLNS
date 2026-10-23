<?php

namespace App\Services;

use App\Models\User;
use App\Services\Backup\MysqlDumper;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use ZipArchive;

// Sao lưu dữ liệu (2026-10-23, theo yêu cầu người dùng — ngoài phạm vi 2 tài liệu gốc, phục vụ vận hành thực tế).
// Mỗi bản = 1 file qlns-backup-YYYY-MM-DD_HHMMSS.zip trong config('backup.path'):
//   database.sql   toàn bộ CSDL (MysqlDumper)
//   files/<nhãn>/  file tải lên (config('backup.sources'))
//   manifest.json  thông tin bản sao lưu
// Tự chạy hằng ngày (routes/console.php), Admin bấm "Sao lưu ngay" ở Cài đặt hệ thống (chạy qua queue — RunBackup).
// Khôi phục CHỈ bằng lệnh `php artisan backup:restore` để không ai bấm nhầm ghi đè dữ liệu đang dùng.
class BackupService
{
    private const NAME_PATTERN = '/^qlns-backup-(\d{4}-\d{2}-\d{2}_\d{6})\.zip$/';

    public function __construct(
        private readonly MysqlDumper $dumper,
        private readonly NotificationService $notificationService,
        private readonly LogService $logService,
    ) {
    }

    /**
     * Sao lưu + ghi nhật ký + báo kết quả. $requestedBy null = lịch tự động (thành công thì không báo ai cho đỡ ồn).
     * Lỗi thì báo mọi người có quyền system.backup và trả null — không ném lỗi để queue không chạy lại nhiều lần.
     */
    public function run(?User $requestedBy = null): ?array
    {
        try {
            $backup = $this->create();
        } catch (Throwable $e) {
            Log::error('Sao lưu dữ liệu thất bại: '.$e->getMessage(), ['exception' => $e]);
            $who = $requestedBy ? "do {$requestedBy->user_name} yêu cầu" : 'tự động';

            foreach ($this->managers() as $user) {
                $this->notificationService->send(
                    $user,
                    'backup.failed',
                    'Sao lưu dữ liệu thất bại',
                    "Bản sao lưu {$who} không tạo được: ".Str::limit($e->getMessage(), 200),
                );
            }

            return null;
        }

        $this->logService->recordBackupEvent('backup_created', $requestedBy, $backup['name']);

        if ($requestedBy !== null) {
            $this->notificationService->send(
                $requestedBy,
                'backup.completed',
                'Đã sao lưu dữ liệu',
                "Bản sao lưu {$backup['name']} (".self::humanSize($backup['size']).') đã sẵn sàng để tải về.',
                ['file' => $backup['name']],
            );
        }

        return $backup;
    }

    /** Tạo 1 bản sao lưu rồi xóa các bản cũ vượt quá config('backup.keep'). */
    public function create(): array
    {
        $directory = $this->directory();
        $createdAt = now();
        $name = 'qlns-backup-'.$createdAt->format('Y-m-d_His').'.zip';
        $sqlPath = "{$directory}/{$name}.sql.tmp";
        // Ghi ra .part rồi mới đổi tên: danh sách không bao giờ hiện bản đang ghi dở.
        $partPath = "{$directory}/{$name}.part";

        try {
            $this->dumper->dump($sqlPath);

            $zip = new ZipArchive();
            if ($zip->open($partPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Không tạo được file nén {$partPath}.");
            }

            $zip->addFile($sqlPath, 'database.sql');
            $fileCount = 0;
            foreach (config('backup.sources') as $label => $source) {
                if (! is_dir($source)) {
                    continue;
                }
                foreach (File::allFiles($source, true) as $file) {
                    $zip->addFile($file->getPathname(), "files/{$label}/".str_replace('\\', '/', $file->getRelativePathname()));
                    $fileCount++;
                }
            }
            $zip->addFromString('manifest.json', json_encode([
                'app' => config('app.name'),
                'created_at' => $createdAt->toIso8601String(),
                'database' => config('database.connections.'.config('database.default').'.database'),
                'files' => $fileCount,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // close() mới thật sự đọc các file đã addFile() — phải xong bước này mới được xóa file .sql tạm.
            if (! $zip->close()) {
                throw new RuntimeException('Ghi file nén bản sao lưu thất bại.');
            }
            rename($partPath, "{$directory}/{$name}");
        } finally {
            File::delete([$sqlPath, $partPath]);
        }

        $this->prune();

        return $this->describe("{$directory}/{$name}");
    }

    /** Các bản sao lưu, mới nhất trước. */
    public function list(): array
    {
        return $this->backupFiles()->map(fn (string $path) => $this->describe($path))->all();
    }

    /** Xóa các bản cũ, chỉ giữ config('backup.keep') bản mới nhất. Trả về số bản đã xóa. */
    public function prune(): int
    {
        $old = $this->backupFiles()->slice(max(1, config('backup.keep')));
        File::delete($old->all());

        return $old->count();
    }

    /** Đường dẫn file của 1 bản sao lưu theo tên — chặn tên lạ (vd "../.env") để không tải được file khác. */
    public function path(string $name): string
    {
        $path = config('backup.path').'/'.$name;

        if (! preg_match(self::NAME_PATTERN, $name) || ! is_file($path)) {
            throw new NotFoundHttpException('Không tìm thấy bản sao lưu.');
        }

        return $path;
    }

    /**
     * Khôi phục từ 1 bản sao lưu ($file: tên bản trong thư mục sao lưu, hoặc đường dẫn tới file .zip).
     * $database khác CSDL đang dùng -> nạp vào CSDL đó (tự tạo nếu chưa có) để kiểm tra bản sao lưu mà không đụng
     * dữ liệu thật. $withFiles = chép lại cả file tải lên.
     *
     * @return array{statements: int, files: int}
     */
    public function restore(string $file, ?string $database = null, bool $withFiles = true): array
    {
        $path = is_file($file) ? $file : $this->path($file);
        $zip = new ZipArchive();

        if ($zip->open($path) !== true || $zip->locateName('database.sql') === false) {
            throw new RuntimeException('File này không phải bản sao lưu QLNS hợp lệ.');
        }

        $sqlPath = tempnam(sys_get_temp_dir(), 'qlns-restore-');

        try {
            $this->copyEntry($zip, 'database.sql', $sqlPath);
            $statements = $this->importSql($sqlPath, $this->restoreConnection($database));
            $files = $withFiles ? $this->extractFiles($zip) : 0;
        } finally {
            $zip->close();
            File::delete($sqlPath);
        }

        return ['statements' => $statements, 'files' => $files];
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 1, ',', '.').' '.$units[$unit];
    }

    private function directory(): string
    {
        $directory = config('backup.path');
        File::ensureDirectoryExists($directory, 0755);

        return $directory;
    }

    /** @return Collection<int, string> đường dẫn các bản sao lưu, mới nhất trước (tên chứa ngày giờ nên sắp theo tên). */
    private function backupFiles(): Collection
    {
        $directory = config('backup.path');
        if (! is_dir($directory)) {
            return collect();
        }

        return collect(File::files($directory))
            ->filter(fn ($file) => preg_match(self::NAME_PATTERN, $file->getFilename()))
            ->map(fn ($file) => $file->getPathname())
            ->sortDesc()
            ->values();
    }

    private function describe(string $path): array
    {
        preg_match(self::NAME_PATTERN, basename($path), $match);

        return [
            'name' => basename($path),
            'size' => filesize($path),
            'created_at' => Carbon::createFromFormat('Y-m-d_His', $match[1])->toIso8601String(),
        ];
    }

    /** @return Collection<int, User> */
    private function managers(): Collection
    {
        return User::withPermission('system.backup')->where('status', 'active')->get();
    }

    private function restoreConnection(?string $database): Connection
    {
        $default = config('database.default');
        if ($database === null || $database === config("database.connections.{$default}.database")) {
            return DB::connection();
        }

        if (! preg_match('/^\w+$/', $database)) {
            throw new InvalidArgumentException('Tên CSDL chỉ được gồm chữ, số và dấu gạch dưới.');
        }

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.backup_restore' => ['database' => $database] + config("database.connections.{$default}")]);
        DB::purge('backup_restore');

        return DB::connection('backup_restore');
    }

    // File .sql của MysqlDumper: mỗi câu lệnh kết thúc bằng ';' ở cuối dòng, chuỗi dữ liệu không chứa xuống dòng thật
    // (đã được escape thành \n) -> gom từng dòng tới khi gặp ';' cuối dòng là đủ 1 câu lệnh.
    private function importSql(string $sqlPath, Connection $connection): int
    {
        $handle = fopen($sqlPath, 'r');
        $buffer = '';
        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                    continue;
                }

                $buffer .= $line;
                if (str_ends_with($trimmed, ';')) {
                    $connection->unprepared($buffer);
                    $buffer = '';
                    $count++;
                }
            }

            if (trim($buffer) !== '') {
                $connection->unprepared($buffer);
                $count++;
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    private function extractFiles(ZipArchive $zip): int
    {
        $count = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (! preg_match('#^files/([^/]+)/(.+[^/])$#', $entry, $match) || str_contains($match[2], '..')) {
                continue;
            }

            $root = config('backup.sources')[$match[1]] ?? null;
            if ($root === null) {
                continue;
            }

            $target = $root.'/'.$match[2];
            File::ensureDirectoryExists(dirname($target));
            $this->copyEntry($zip, $entry, $target);
            $count++;
        }

        return $count;
    }

    private function copyEntry(ZipArchive $zip, string $entry, string $target): void
    {
        $in = $zip->getStream($entry);
        $out = fopen($target, 'w');

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
