<?php

namespace App\Services\Backup;

use Ifsnop\Mysqldump\Mysqldump;

// Xuất toàn bộ CSDL MySQL đang dùng ra 1 file .sql — viết bằng PHP thuần (thư viện ifsnop/mysqldump-php) nên không
// cần cài lệnh mysqldump trong container. Tách riêng class để test thay bằng bản giả (test chạy SQLite).
class MysqlDumper
{
    public function dump(string $path): void
    {
        $connection = config('database.connections.'.config('database.default'));

        $dumper = new Mysqldump(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
            $connection['username'],
            $connection['password'],
            [
                // Khôi phục: xóa bảng cũ rồi tạo lại đúng như lúc sao lưu.
                'add-drop-table' => true,
                // Đọc trong 1 transaction: dữ liệu nhất quán mà không khóa bảng, người dùng vẫn làm việc bình thường.
                'single-transaction' => true,
                'lock-tables' => false,
                'default-character-set' => Mysqldump::UTF8MB4,
            ],
        );

        $dumper->start($path);
    }
}
