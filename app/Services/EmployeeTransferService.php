<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTransfer;
use App\Repositories\EmployeeTransferRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeTransferService
{
    public function __construct(
        private readonly EmployeeTransferRepository $employeeTransferRepository,
        private readonly PositionService $positionService,
        private readonly ReportingLineService $reportingLineService,
    ) {
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->employeeTransferRepository->listByEmployee($employee);
    }

    // Dòng đầu tiên của lịch sử luân chuyển: nhân viên mới vào phòng ban nào, chức vụ
    // gì, từ ngày vào làm. Gọi từ EmployeeService::create().
    public function recordOnboarding(Employee $employee): ?EmployeeTransfer
    {
        if ($employee->department_id === null) {
            return null;
        }

        return $this->employeeTransferRepository->create([
            'employee_id' => $employee->id,
            'type' => EmployeeTransfer::TYPE_ONBOARD,
            'from_department_id' => null,
            'to_department_id' => $employee->department_id,
            'old_position_id' => null,
            'new_position_id' => $employee->position_id,
            'new_manager_id' => $employee->manager_id,
            'effective_date' => $employee->hire_date?->toDateString() ?? now()->toDateString(),
            'reason' => 'Tiếp nhận nhân viên mới',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    // Phòng ban/chức vụ đổi NGOÀI màn điều chuyển (sửa hồ sơ, bổ nhiệm/thôi Trưởng
    // phòng) — vẫn ghi vào lịch sử để không có khoảng trống. Không đổi gì thì bỏ qua.
    public function recordAdjustment(Employee $employee, ?int $oldDepartmentId, ?int $oldPositionId, string $reason): ?EmployeeTransfer
    {
        $employee->refresh();

        // Chưa thuộc phòng ban nào thì không có gì để ghi (lịch sử luôn gắn với 1 phòng ban).
        if ($employee->department_id === null) {
            return null;
        }

        if ((int) $oldDepartmentId === (int) $employee->department_id && (int) $oldPositionId === (int) $employee->position_id) {
            return null;
        }

        return $this->employeeTransferRepository->create([
            'employee_id' => $employee->id,
            'type' => EmployeeTransfer::TYPE_ADJUSTMENT,
            'from_department_id' => $oldDepartmentId,
            'to_department_id' => $employee->department_id,
            'old_position_id' => $oldPositionId,
            'new_position_id' => $employee->position_id,
            'new_manager_id' => $employee->manager_id,
            'effective_date' => now()->toDateString(),
            'reason' => $reason,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    // Tạo bản ghi luân chuyển ĐỒNG THỜI áp dụng ngay vào hồ sơ nhân viên
    // (department_id/position_id/manager_id) — dự án chưa có hàng chờ/lịch
    // chạy nền (queue worker, xem Ghi chú ở CODE_MAP mục Xác thực) nên không
    // thể tự động áp dụng đúng vào "effective_date" trong tương lai; coi như
    // luân chuyển có hiệu lực ngay tại thời điểm tạo, effective_date chỉ mang
    // tính ghi nhận/báo cáo.
    public function create(Employee $employee, array $data, int $approvedBy, ?UploadedFile $decisionFile): EmployeeTransfer
    {
        if ((int) $data['to_department_id'] === (int) $employee->department_id) {
            throw ValidationException::withMessages([
                'to_department_id' => 'Phòng ban mới phải khác phòng ban hiện tại của nhân viên.',
            ]);
        }

        // "Quản lý mới" KHÔNG còn chọn tay (2026-09-29) — tự suy ra theo
        // phòng ban mới sau khi điều chuyển (ReportingLineService), vẫn ghi
        // vào new_manager_id của bản ghi luân chuyển để giữ lịch sử.
        unset($data['new_manager_id']);

        // Đang là Trưởng phòng (Position type='head') mà bị điều sang phòng ban
        // khác thì không còn là Trưởng phòng của phòng cũ nữa: tự để trống
        // Department.manager_id (không tự chọn người thay thế, xem syncHeadPosition
        // của DepartmentService — đây là chiều ngược lại, kích hoạt từ luân chuyển
        // chứ không phải từ việc gán/đổi Trưởng phòng trực tiếp) và tự hạ Chức vụ
        // về "Nhân viên" của phòng ban mới, trừ khi lượt điều chuyển này đã tự chọn
        // sẵn new_position_id.
        $wasHeadMovingOut = $employee->position?->type === 'head';

        return DB::transaction(function () use ($employee, $data, $approvedBy, $decisionFile, $wasHeadMovingOut) {
            $data['employee_id'] = $employee->id;
            $data['type'] = EmployeeTransfer::TYPE_TRANSFER;
            $data['from_department_id'] = $employee->department_id;
            $data['old_position_id'] = $employee->position_id;
            $data['approved_by'] = $approvedBy;
            $data['approved_at'] = now();

            if ($decisionFile) {
                $data['decision_file'] = $decisionFile->store('employee-transfers', 'local');
            }

            $transfer = $this->employeeTransferRepository->create($data);

            if ($wasHeadMovingOut) {
                Department::whereKey($employee->department_id)->update(['manager_id' => null]);
            }

            $newPositionId = $data['new_position_id']
                ?? ($wasHeadMovingOut
                    ? $this->positionService->ensureDefaultPosition(Department::findOrFail($data['to_department_id']))->id
                    : $employee->position_id);

            $oldDepartmentId = $employee->department_id;

            $employee->forceFill([
                'department_id' => $data['to_department_id'],
                'position_id' => $newPositionId,
            ])->save();

            $this->reportingLineService->syncEmployee($employee);
            // Trưởng phòng rời đi thì phòng cũ mất Trưởng phòng -> quản lý của
            // cả cây phòng cũ phải tính lại.
            if ($wasHeadMovingOut && $oldDepartment = Department::find($oldDepartmentId)) {
                $this->reportingLineService->syncDepartmentTree($oldDepartment);
            }

            // Chức vụ mới thực tế (tự hạ về "Nhân viên" khi Trưởng phòng rời đi) để lịch sử
            // ghi đúng chức vụ sau điều chuyển, không để trống khi HR không chọn.
            $transfer->forceFill([
                'new_manager_id' => $employee->manager_id,
                'new_position_id' => $newPositionId,
            ])->save();

            return $transfer;
        });
    }
}
