<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\WorkShift;

// Dùng CHUNG cho AttendanceService (tổng ngày công hiển thị ở trang Lịch sử
// chấm công) và PayrollService (ngày công dùng để tính lương) — trước đây 2
// nơi tính "ngày công" KHÁC NHAU: AttendanceService dùng work_coefficient cấu
// hình tay theo từng CA (không đổi dù làm ít/nhiều giờ trong ca đó),
// PayrollService dùng công thức tuyến tính actual_work_minutes/
// standard_work_minutes. Quyết định 2026-09-16 (theo yêu cầu người dùng): bỏ
// cả 2 cách cũ, dùng 1 bảng BẬC THANG duy nhất — tính theo % thời gian làm so
// với ca CỦA CHÍNH ngày đó, kết hợp thêm mức TRẦN (cap) do đi muộn/về sớm
// theo phút, RỒI NHÂN với work_coefficient của ca đó.
//
// Bug thật đã vấp (phát hiện qua câu hỏi của người dùng, tái hiện bằng
// tinker trước khi sửa): nếu bỏ HẲN work_coefficient, 1 nhân viên chia ca
// sáng+chiều (mỗi ca chỉ đáng 0.5 ngày) làm đủ CẢ 2 ca sẽ bị tính thành 2.0
// ngày công thay vì đúng 1.0 — vì bậc thang chỉ trả lời "làm đủ/thiếu bao
// nhiêu SO VỚI CHÍNH CA ĐÓ", không trả lời "ca này đáng giá bao nhiêu phần
// của 1 ngày". work_coefficient vẫn cần thiết để trả lời câu hỏi thứ 2 — 2
// câu hỏi độc lập, phải NHÂN với nhau chứ không phải chọn 1 trong 2:
//   hệ số công (1 ca) = min(bậc theo % giờ làm, trần theo phút trễ) × work_coefficient

class WorkTimeCalculationService
{
    // Bậc quy đổi theo tỉ lệ actual_work_minutes / standard_work_minutes.
    // Sắp GIẢM DẦN theo min_ratio, dò từ trên xuống, khớp mốc đầu tiên mà tỉ
    // lệ >= min_ratio. Ratio có thể > 1.0 (làm dư giờ không đăng ký OT chính
    // thức) vẫn chỉ tính tối đa 1.0 — phần dư phải đi qua overtime_minutes
    // (tính riêng ở PayrollService::calculateWorkedMetrics()), không được tự
    // cộng thêm ngày công ở đây.
    private const DAY_EQUIVALENT_BRACKETS = [
        ['min_ratio' => 1.0, 'coefficient' => 1.0],
        ['min_ratio' => 0.75, 'coefficient' => 0.75],
        ['min_ratio' => 0.5, 'coefficient' => 0.5],
        ['min_ratio' => 0.25, 'coefficient' => 0.25],
        ['min_ratio' => 0.0, 'coefficient' => 0.0],
    ];

    // Mức TRẦN (cap) cho ngày công theo số phút đi muộn/về sớm — lấy số phút
    // LỚN HƠN giữa late_minutes/early_leave_minutes của 1 bản ghi chấm công.
    // Từ 15 phút trở xuống coi là chưa đáng phạt thêm (mỗi ca đã có riêng
    // late_grace_minutes/early_leave_grace_minutes xử lý phần rất nhỏ trước
    // khi late_minutes/early_leave_minutes được ghi nhận > 0 rồi).
    private const LATENESS_PENALTY_BRACKETS = [
        ['max_minutes' => 15, 'cap' => 1.0],
        ['max_minutes' => 30, 'cap' => 0.75],
        ['max_minutes' => 60, 'cap' => 0.5],
        ['max_minutes' => null, 'cap' => 0.25],
    ];

    public function dayEquivalentFor(Attendance $attendance, ?WorkShift $workShift): float
    {
        $standardMinutes = $workShift?->standard_work_minutes;

        if (! $standardMinutes) {
            return 0.0; // Ca đã bị xóa hoặc thiếu số phút chuẩn — bỏ qua an toàn.
        }

        $ratio = ($attendance->actual_work_minutes ?? 0) / $standardMinutes;
        $hoursCoefficient = $this->bracketForRatio($ratio);

        $latenessMinutes = max($attendance->late_minutes ?? 0, $attendance->early_leave_minutes ?? 0);
        $latenessCap = $this->latenessCapFor($latenessMinutes);

        $dayWeight = (float) ($workShift->work_coefficient ?? 1.0);

        return min($hoursCoefficient, $latenessCap) * $dayWeight;
    }

    private function bracketForRatio(float $ratio): float
    {
        foreach (self::DAY_EQUIVALENT_BRACKETS as $bracket) {
            if ($ratio >= $bracket['min_ratio']) {
                return $bracket['coefficient'];
            }
        }

        return 0.0;
    }

    private function latenessCapFor(int $minutes): float
    {
        foreach (self::LATENESS_PENALTY_BRACKETS as $bracket) {
            if ($bracket['max_minutes'] === null || $minutes <= $bracket['max_minutes']) {
                return $bracket['cap'];
            }
        }

        return 0.25;
    }
}
