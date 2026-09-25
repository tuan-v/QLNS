<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

// 2026-09-25, theo yêu cầu người dùng: nhắc nhân viên CHẤM CÔNG RA nếu ca đã
// kết thúc quá 5 phút mà `last_check_out_at` vẫn null — đúng edge case "Chấm
// công quên Check-out" (Ke-hoach-trien-khai-du-an-QLNS.docx, Ngày 42), tài
// liệu chỉ nêu tên chứ không quy định cách xử lý cụ thể (ngưỡng "5 phút" và
// hình thức "gửi thông báo trong app" là giả định của phiên làm việc này).
//
// Lý do CẦN nhắc: PayrollService::calculateWorkedMetrics() ->
// WorkTimeCalculationService::dayEquivalentFor() tính ngày công dựa trên
// actual_work_minutes — cột này CHỈ được set lúc checkOut(), không hề liên
// quan tới approval_status. Nghĩa là HR có thể đã duyệt lượt chấm công VÀO
// (WorkShiftService cho duyệt ngay từ lúc vào ca), nhưng nếu không có lượt
// RA thì ngày đó vẫn tính 0 ngày công — nhắc sớm để nhân viên còn kịp chấm
// công ra hoặc xin "Điều chỉnh công" (mục 17, type=correction) bổ sung giờ ra.
//
// Chỉ gửi ĐÚNG 1 LẦN cho mỗi bản ghi: checkout_reminder_sent_at đánh dấu
// ngay sau khi gửi thành công, job những lần chạy sau bỏ qua bản ghi đã có
// cột này (không lặp lại tới khi được xử lý xong). Chạy mỗi phút (xem
// routes/console.php) để bám sát mốc "quá 5 phút" nhất có thể.
class RemindMissingCheckout extends Command
{
    protected $signature = 'attendance:remind-checkout';

    protected $description = 'Gửi thông báo nhắc chấm công ra cho các bản ghi đã quá giờ tan ca 5 phút mà chưa check-out';

    private const GRACE_MINUTES = 5;

    public function __construct(private readonly NotificationService $notificationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = Carbon::now();

        $attendances = Attendance::query()
            ->whereDate('attendance_date', $now->toDateString())
            ->whereNotNull('first_check_in_at')
            ->whereNull('last_check_out_at')
            ->whereNull('checkout_reminder_sent_at')
            ->with(['employee.user', 'workShift'])
            ->get();

        $reminded = 0;

        foreach ($attendances as $attendance) {
            $workShift = $attendance->workShift;
            $user = $attendance->employee?->user;

            // Không có Ca (dữ liệu hỏng) hoặc nhân viên không có tài khoản
            // đăng nhập (không có ai để nhận thông báo trong-app) — bỏ qua.
            if ($workShift === null || $user === null) {
                continue;
            }

            $shiftEndsAt = Carbon::parse($attendance->attendance_date->toDateString().' '.$workShift->end_time);

            if ($now->lt($shiftEndsAt->copy()->addMinutes(self::GRACE_MINUTES))) {
                continue;
            }

            $this->notificationService->send(
                $user,
                'attendance.checkout_reminder',
                'Bạn quên chấm công ra?',
                "Ca \"{$workShift->name}\" đã kết thúc lúc {$shiftEndsAt->format('H:i')} nhưng hệ thống chưa ghi nhận chấm công ra — ngày này sẽ không được tính công nếu bạn không chấm công ra.",
                ['attendance_id' => $attendance->id],
            );

            $attendance->update(['checkout_reminder_sent_at' => $now]);
            $reminded++;
        }

        if ($reminded > 0) {
            $this->info("Đã nhắc chấm công ra cho {$reminded} bản ghi.");
        }

        return self::SUCCESS;
    }
}
