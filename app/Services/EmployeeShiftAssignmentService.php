<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\WorkShift;
use App\Repositories\EmployeeShiftAssignmentRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeShiftAssignmentService
{
    public function __construct(private readonly EmployeeShiftAssignmentRepository $employeeShiftAssignmentRepository)
    {
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->employeeShiftAssignmentRepository->listByEmployee($employee);
    }

    // 1 nhân viên được gán NHIỀU ca cùng lúc (vd Ca sáng + Ca chiều) — chỉ cấm
    // 2 ca chồng giờ nhau. Không tự đóng ca nào cả (khác bản đầu tiên đã làm) —
    // mỗi ca là 1 bản ghi độc lập, muốn kết thúc/đổi thì Sửa hoặc Xóa đúng
    // bản ghi đó.
    public function create(Employee $employee, array $data): EmployeeShiftAssignment
    {
        $this->assertNoConflict($employee, $data);

        $data['employee_id'] = $employee->id;
        $data['status'] = 'active';

        return DB::transaction(fn () => $this->employeeShiftAssignmentRepository->create($data));
    }

    public function update(Employee $employee, EmployeeShiftAssignment $assignment, array $data): EmployeeShiftAssignment
    {
        $this->assertNoConflict($employee, $data, excludeId: $assignment->id);

        return $this->employeeShiftAssignmentRepository->update($assignment, $data);
    }

    public function delete(EmployeeShiftAssignment $assignment): void
    {
        $this->employeeShiftAssignmentRepository->delete($assignment);
    }

    // Gỡ TOÀN BỘ bản gán ca đang trỏ tới 1 Ca làm việc, khỏi MỌI nhân viên —
    // dùng khi Ca đó bị tắt is_active (xem WorkShiftService::update()). Xóa
    // từng bản ghi qua delete() thường (không dùng bulk query ->delete()) để
    // AuditObserver vẫn ghi vết đầy đủ từng lượt gỡ — bulk delete qua query
    // builder không bắn model event, sẽ lọt khỏi audit log (mục 3 tài liệu
    // yêu cầu "Ghi vết thao tác").
    public function removeAllForWorkShift(int $workShiftId): int
    {
        $assignments = $this->employeeShiftAssignmentRepository->listByWorkShift($workShiftId);

        foreach ($assignments as $assignment) {
            $this->employeeShiftAssignmentRepository->delete($assignment);
        }

        return $assignments->count();
    }

    // 2 bản gán ca của CÙNG 1 nhân viên coi là chồng nhau khi cả 3 điều kiện
    // đều đúng: (1) khoảng ngày [effective_from, effective_to] giao nhau
    // (effective_to = null nghĩa là không giới hạn), (2) có chung ít nhất 1
    // ngày trong tuần (work_days giao nhau), (3) khung giờ của 2 Ca làm việc
    // giao nhau. Thiếu 1 trong 3 thì KHÔNG chồng — vd Ca sáng + Ca chiều khác
    // ngày trong tuần hoàn toàn hoặc khác giờ hoàn toàn thì vẫn gán được bình
    // thường. So giờ bằng so chuỗi "HH:MM:SS" trực tiếp (không parse Carbon) —
    // hợp lệ vì chuỗi giờ có độ dài cố định, so chuỗi ra đúng kết quả so số;
    // chưa xử lý ca qua đêm (end_time < start_time) — cùng giả định với
    // StoreWorkShiftRequest, tính sau khi có nhu cầu ca đêm thật.
    private function assertNoConflict(Employee $employee, array $data, ?int $excludeId = null): void
    {
        $newShift = WorkShift::findOrFail($data['work_shift_id']);
        $newFrom = $data['effective_from'];
        $newTo = $data['effective_to'] ?? null;
        $newWorkDays = $data['work_days'];

        $others = $employee->shiftAssignments()
            ->with('workShift')
            ->when($excludeId, fn ($query, $id) => $query->whereKeyNot($id))
            ->get();

        foreach ($others as $other) {
            $dateOverlap = ($other->effective_to === null || $newFrom <= $other->effective_to->toDateString())
                && ($newTo === null || $other->effective_from->toDateString() <= $newTo);

            if (! $dateOverlap) {
                continue;
            }

            if (! array_intersect($newWorkDays, $other->work_days)) {
                continue;
            }

            $timeOverlap = $newShift->start_time < $other->workShift->end_time
                && $other->workShift->start_time < $newShift->end_time;

            if ($timeOverlap) {
                throw ValidationException::withMessages([
                    'work_shift_id' => 'Khung giờ ca này chồng với ca "'.$other->workShift->name
                        .'" đã gán cho nhân viên (cùng ngày trong tuần, cùng khoảng thời gian áp dụng).',
                ]);
            }
        }
    }
}
