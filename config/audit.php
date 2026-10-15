<?php

// Nhật ký hoạt động (audit log) — quyết định của người dùng 2026-10-15.
return [
    // Log cũ hơn số tháng này bị job logs:prune xóa (chạy hằng ngày).
    'retention_months' => (int) env('AUDIT_RETENTION_MONTHS', 24),

    // Trường nhạy cảm chỉ hiện 4 ký tự cuối trên trang Nhật ký (NĐ 13/2023 — hạn chế lộ
    // dữ liệu không cần thiết). Dữ liệu gốc trong DB vẫn giữ nguyên.
    'masked_fields' => ['cccd', 'personal_tax_code', 'account_number'],

    // Không bao giờ hiện (bí mật / không mang ý nghĩa nghiệp vụ).
    'hidden_fields' => ['password', 'remember_token', 'token_hash', 'updated_at'],
];
