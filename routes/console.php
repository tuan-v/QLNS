<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laravel 12: lịch chạy đăng ký thẳng ở đây (không còn app/Console/Kernel.php).
// Chạy contracts:activate-pending TRƯỚC contracts:expire mỗi ngày — kích
// hoạt hợp đồng vừa tới ngày bắt đầu (kèm supersede hợp đồng cũ) xong mới
// dọn tiếp hợp đồng đã quá end_date, tránh 2 job dẫm chân theo thứ tự sai.
// 00:05 — lùi 5 phút sau nửa đêm để chắc chắn ngày "hôm qua" đã thực sự qua.
Schedule::command('contracts:activate-pending')->dailyAt('00:05');
Schedule::command('contracts:expire')->dailyAt('00:06');
