<?php

namespace App\Console\Commands;

use App\Services\HolidayService;
use Illuminate\Console\Command;

// Hằng ngày: (1) nhắc HR xác nhận các dịp nghỉ sắp tới chưa được xác nhận;
// (2) dịp ĐÃ xác nhận vào khoảng "báo trước" mà chưa thông báo thì gửi cho mọi
// tài khoản đang hoạt động — HR không phải gửi tay.
class NotifyUpcomingHolidays extends Command
{
    protected $signature = 'holidays:notify';

    protected $description = 'Nhắc HR xác nhận lịch nghỉ và gửi thông báo nghỉ lễ cho nhân viên';

    public function handle(HolidayService $holidayService): int
    {
        $result = $holidayService->runDailyNotifications();
        $this->info("Nhắc HR xác nhận {$result['reminded']} dịp; thông báo nhân viên {$result['notified']} dịp.");

        return self::SUCCESS;
    }
}
