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
// 2026-09-24, theo yêu cầu người dùng — lưới an toàn hằng ngày, xem comment
// đầu file AssignMissingDefaultShift.php.
Schedule::command('shifts:assign-missing-default')->dailyAt('00:07');
// "Tích lũy phép năm" (2026-09-24, theo yêu cầu người dùng) — chạy HẰNG
// NGÀY (không phải hằng tháng) vì mốc thưởng thâm niên cần đúng ngày, xem
// comment đầu LeaveAccrualService.php.
Schedule::command('leave:sync-accrual')->dailyAt('00:20');
// Nhắc chấm công ra (2026-09-25, theo yêu cầu người dùng) — chạy MỖI PHÚT để
// bám sát ngưỡng "quá 5 phút" sau giờ tan ca, xem comment đầu
// RemindMissingCheckout.php. Mỗi bản ghi chỉ được nhắc đúng 1 lần (cột
// checkout_reminder_sent_at) nên chạy dày không tạo trùng lặp thông báo.
Schedule::command('attendance:remind-checkout')->everyMinute();
