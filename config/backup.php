<?php

// Sao lưu tự động (2026-10-23, theo yêu cầu người dùng — ngoài phạm vi 2 tài liệu gốc). Mỗi bản là 1 file .zip gồm
// database.sql (toàn bộ CSDL) + files/ (file tải lên: hợp đồng, CV, minh chứng, ảnh...). Xem App\Services\BackupService.
return [
    // Thư mục chứa bản sao lưu. Nên trỏ sang ổ đĩa / thư mục đồng bộ khác (Google Drive, NAS) để hỏng máy vẫn còn bản.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Giữ N bản mới nhất, bản cũ hơn tự xóa sau mỗi lần sao lưu.
    'keep' => (int) env('BACKUP_KEEP', 14),

    // Giờ chạy tự động mỗi ngày (giờ Việt Nam, xem routes/console.php).
    'time' => env('BACKUP_TIME', '02:00'),

    // Thư mục file tải lên được đưa vào bản sao lưu: tên trong file zip => đường dẫn thật.
    'sources' => [
        'private' => storage_path('app/private'),
        'public' => storage_path('app/public'),
    ],
];
