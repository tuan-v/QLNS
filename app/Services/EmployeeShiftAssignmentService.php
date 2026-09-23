<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\WorkShift;
use App\Repositories\EmployeeShiftAssignmentRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeShiftAssignmentService
{
    // PHƯƠNG ÁN DỰ PHÒNG khi Ca mặc định chưa từng cấu hình `work_days`
    // (2026-09-24: giờ HR chọn ngày làm việc thật ở trang Cài đặt — xem
    // WorkShift::$casts, Settings.vue — cột này thường có giá trị). Chỉ rơi
    // về T2-T6 cứng khi cột `work_days` của Ca mặc định là NULL (ca tạo
    // trước migration này, hoặc lần đầu thiết lập chưa lưu Cài đặt lần nào).
    private const DEFAULT_WORK_DAYS = [1, 2, 3, 4, 5];

    public function __construct(private readonly EmployeeShiftAssignmentRepository $employeeShiftAssignmentRepository)
    {
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->employeeShiftAssignmentRepository->listByEmployee($employee);
    }

    // Gán "Ca mặc định" (is_default=true) cho nhân viên VỪA tạo (2026-09-23,
    // theo yêu cầu người dùng — gọi từ EmployeeService::create()). Bỏ qua
    // assertNoConflict(): nhân viên mới không thể có bản gán ca nào từ
    // trước nên không cần kiểm tra chồng giờ. Trả null (không tạo gì, không
    // báo lỗi) nếu công ty CHƯA cấu hình ca mặc định nào — tạo nhân viên vẫn
    // phải thành công bình thường.
    public function assignDefaultShift(Employee $employee): ?EmployeeShiftAssignment
    {
        $defaultShift = WorkShift::default()->active()->first();

        if (! $defaultShift) {
            return null;
        }

        return $this->employeeShiftAssignmentRepository->create([
            'employee_id' => $employee->id,
            'work_shift_id' => $defaultShift->id,
            'effective_from' => $employee->hire_date
                ? Carbon::parse($employee->hire_date)->toDateString()
                : now()->toDateString(),
            'effective_to' => null,
            'work_days' => $defaultShift->work_days ?: self::DEFAULT_WORK_DAYS,
            'status' => 'active',
        ]);
    }

    // Cài đặt đổi "ngày làm việc" của Ca mặc định (2026-09-24, theo yêu cầu
    // người dùng) — áp dụng NGAY cho mọi nhân viên đang theo ca đó, nhất
    // quán với cách giờ vào/ra đã propagate tự động vì cùng chung 1 dòng
    // WorkShift (xem comment đầu Settings.vue). Chỉ đụng bản gán "đang mở"
    // (effective_to NULL — xem listOpenEndedByWorkShift()); bản gán 1-ngày
    // của "Xin làm ngoài lịch/OT" (createOneOffAssignment(), effective_to đã
    // chốt) là ngoại lệ đã xong việc, không phải "đang theo mặc định" nữa
    // nên không bị ghi đè.
    public function resyncOpenEndedWorkDays(int $workShiftId, array $workDays): int
    {
        $assignments = $this->employeeShiftAssignmentRepository->listOpenEndedByWorkShift($workShiftId);

        foreach ($assignments as $assignment) {
            $this->employeeShiftAssignmentRepository->update($assignment, ['work_days' => $workDays]);
        }

        return $assignments->count();
    }

    // "Xin làm ngoài lịch" ĐÃ được duyệt (2026-09-23, theo yêu cầu người
    // dùng — xem AttendanceAdjustmentService::decide()) — tạo 1 bản gán ca
    // CHỈ ÁP DỤNG ĐÚNG 1 NGÀY (`effective_from` = `effective_to` = ngày đã
    // đăng ký, `work_days` chỉ chứa đúng THỨ của ngày đó) để mở khóa cho
    // nhân viên tự chấm công vào/ra bình thường hôm đó — không tạo
    // Attendance trực tiếp (khác 'supplement') vì nhân viên CHƯA làm, còn
    // phải tự bấm Chấm công khi tới ngày. Không gọi assertNoConflict(): đã
    // kiểm "chưa có ca nào vào ngày này" lúc NỘP đơn
    // (assertExtraShiftRequestIsValid()) — tình huống chồng ca thật sự hiếm
    // (chỉ xảy ra nếu HR gán thêm ca khác đúng lúc đơn đang chờ duyệt).
    public function createOneOffAssignment(Employee $employee, WorkShift $workShift, Carbon $date): EmployeeShiftAssignment
    {
        return $this->employeeShiftAssignmentRepository->create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => $date->toDateString(),
            'effective_to' => $date->toDateString(),
            'work_days' => [$date->dayOfWeekIso],
            'status' => 'active',
        ]);
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

    // Gỡ TOÀN BỘ bản gán ca của 1 nhân viên — dùng khi XÓA nhân viên (xem
    // EmployeeService::delete()), cùng lý do/cách làm với removeAllForWorkShift()
    // ở trên (2026-09-24, phát hiện qua lỗi thật: xóa Ca làm việc KHÔNG gỡ
    // bản gán liên quan, để lại `EmployeeShiftAssignment` mồ côi trỏ tới Ca
    // đã xóa mềm — `AttendanceService::dailyOverview()`/`history()` gọi
    // `$assignment->workShift->id` sập với lỗi "Attempt to read property
    // id on null" vì quan hệ trả về NULL. Nhân viên bị xóa cũng mồ côi y
    // hệt theo chiều ngược lại nếu không dọn ở đây).
    public function removeAllForEmployee(Employee $employee): int
    {
        $assignments = $this->employeeShiftAssignmentRepository->listByEmployee($employee);

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
