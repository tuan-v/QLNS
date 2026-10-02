<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\WorkShift;

// Dùng CHUNG cho AttendanceService (tổng ngày công hiển thị ở trang Lịch sử
// chấm công) và PayrollService (ngày công dùng để tính lương).
//
// Lịch sử: bản 2026-09-16 dùng bảng BẬC THANG kép (bậc theo % giờ làm THỰC
// TẾ, kết hợp trần theo phút trễ/sớm, lấy min 2 cái). Bug thật (phát hiện
// 2026-09-29 qua báo cáo người dùng, tái hiện bằng debug_attendance.php với
// nhân viên Nguyễn Ánh): đi làm ĐÚNG GIỜ (late=0, early=0) nhưng
// actual_work_minutes hụt vài phút so với chuẩn ca (do làm tròn giờ chấm
// công/trừ giờ nghỉ) vẫn bị bậc "% giờ làm" rớt xuống 0.75 — dù không hề
// trễ/sớm chút nào. Quyết định 2026-09-29 (theo yêu cầu người dùng): BỎ HẲN
// bậc thang theo % giờ làm — chỉ còn đúng 1 quy tắc duy nhất, dựa vào số
// phút trễ/về sớm:
//   - Trễ hoặc về sớm <= 30 phút (hoặc được duyệt miễn trừ late_excused)
//     -> luôn tính đủ 1.0 ngày (theo work_coefficient của ca).
//   - Trễ hoặc về sớm > 30 phút -> phạt LIÊN TỤC theo đúng tỉ lệ
//     actual_work_minutes/standard_work_minutes (không bậc thang, không bị
//     "rơi cliff" như bản cũ).
// work_coefficient của ca vẫn LUÔN nhân riêng ở bước cuối (không đổi so với
// bản cũ) — trả lời câu hỏi khác: "ca này đáng giá bao nhiêu phần của 1
// ngày" (ca nửa ngày = 0.5), độc lập với việc "làm đủ/thiếu giờ của chính
// ca đó". Bỏ nhân này sẽ tái diễn đúng bug cũ: nhân viên làm đủ CẢ 2 ca
// sáng+chiều (mỗi ca 0.5) bị cộng thành 2.0 thay vì đúng 1.0.
class WorkTimeCalculationService
{
    // Ngưỡng phút trễ/về sớm bắt đầu bị trừ công. Từ ngưỡng này trở xuống
    // (bao gồm cả mốc cảnh báo 15 phút hiển thị riêng ở giao diện) vẫn tính
    // đủ 1.0 — cảnh báo 15 phút chỉ là thông báo cho HR biết, KHÔNG trừ
    // công; chỉ khi vượt hẳn 30 phút mới thực sự ảnh hưởng tới lương.
    private const PENALTY_THRESHOLD_MINUTES = 30;

    public function dayEquivalentFor(Attendance $attendance, ?WorkShift $workShift): float
    {
        $standardMinutes = $workShift?->standard_work_minutes;

        if (! $standardMinutes) {
            return 0.0; // Ca đã bị xóa hoặc thiếu số phút chuẩn — bỏ qua an toàn.
        }

        $actualWorkMinutes = (float) ($attendance->actual_work_minutes ?? 0);
        $dayWeight = (float) ($workShift->work_coefficient ?? 1.0);

        // actual_work_minutes chỉ > 0 sau khi đã checkout — chưa checkout
        // thì chưa có gì để tính công, bất kể trễ/sớm hay có miễn trừ hay
        // không (tránh tính đủ công cho ca còn dang dở).
        if ($actualWorkMinutes <= 0) {
            return 0.0;
        }

        if ($attendance->late_excused) {
            return $dayWeight;
        }

        $latenessMinutes = max($attendance->late_minutes ?? 0, $attendance->early_leave_minutes ?? 0);

        if ($latenessMinutes <= self::PENALTY_THRESHOLD_MINUTES) {
            return $dayWeight;
        }

        // Đi muộn/về sớm quá ngưỡng: công chỉ tính tới đúng giờ làm thực tế
        // (tới lúc chấm ra), không có "miễn trừ".
        $ratio = max(0.0, min($actualWorkMinutes / $standardMinutes, 1.0));

        return $ratio * $dayWeight;
    }
}
