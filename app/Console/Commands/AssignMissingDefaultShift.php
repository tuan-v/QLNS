<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\EmployeeShiftAssignmentService;
use Illuminate\Console\Command;

// 2026-09-24, theo yêu cầu người dùng: "bất kỳ nhân viên nào cũng tự động
// có giờ làm việc mặc định như trong Cài đặt (T2-T6)" — không còn coi
// "Gán ca làm việc" (mục 14) là bước BẮT BUỘC với từng người nữa; tab đó
// vẫn giữ nguyên, chỉ còn dùng khi thật sự cần 1 người có lịch RIÊNG hẳn
// (ca đêm, part-time cố định...). Muốn LÀM THÊM/LÀM BÙ ngoài giờ mặc định
// thì dùng "Xin làm ngoài lịch / OT" (mục 17).
//
// Nhân viên MỚI đã tự động có sẵn ngay lúc tạo (EmployeeService::create()
// gọi EmployeeShiftAssignmentService::assignDefaultShift()) — job này là
// LƯỚI AN TOÀN chạy hằng ngày, bắt các trường hợp CÒN THIẾU: (1) nhân viên
// tạo TRƯỚC khi có tính năng "Ca mặc định" (2026-09-23), (2) công ty CHƯA
// cấu hình Ca mặc định lúc tạo nhân viên rồi mới cấu hình sau, (3) nhân
// viên lỡ mất HẾT ca đang có (vd Ca đang theo bị HR tắt hoạt động —
// WorkShiftService::update() tự gỡ mọi bản gán khi tắt is_active).
//
// CHỈ đụng tới nhân viên đang KHÔNG có bất kỳ bản gán ca "active" nào —
// nhân viên đã có ca riêng (dù chỉ 1 ca, dù ca đó không phủ hết tuần) đều
// được BỎ QUA, không ghi đè/thêm gì cả.
class AssignMissingDefaultShift extends Command
{
    protected $signature = 'shifts:assign-missing-default';

    protected $description = 'Tự động gán Ca mặc định (trang Cài đặt) cho nhân viên active/probation đang KHÔNG có bản gán ca nào';

    public function handle(EmployeeShiftAssignmentService $employeeShiftAssignmentService): int
    {
        $employees = Employee::whereIn('employment_status', ['active', 'probation'])
            ->whereDoesntHave('shiftAssignments', fn ($query) => $query->where('status', 'active'))
            ->get();

        $assigned = 0;
        foreach ($employees as $employee) {
            if ($employeeShiftAssignmentService->assignDefaultShift($employee)) {
                $assigned++;
            }
        }

        if ($assigned < $employees->count()) {
            $this->warn('Công ty chưa cấu hình Ca mặc định nào (trang Cài đặt) — không gán được cho '.($employees->count() - $assigned).' nhân viên còn lại.');
        }

        $this->info("Đã gán Ca mặc định cho {$assigned}/{$employees->count()} nhân viên đang thiếu ca.");

        return self::SUCCESS;
    }
}
