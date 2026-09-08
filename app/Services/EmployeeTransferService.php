<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTransfer;
use App\Repositories\EmployeeRepository;
use App\Repositories\EmployeeTransferRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeTransferService
{
    public function __construct(
        private readonly EmployeeTransferRepository $employeeTransferRepository,
        private readonly EmployeeRepository $employeeRepository,
        private readonly PositionService $positionService,
    ) {
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->employeeTransferRepository->listByEmployee($employee);
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

        if (
            ! empty($data['new_manager_id'])
            && $this->employeeRepository->wouldCreateCycle($employee->id, $data['new_manager_id'])
        ) {
            throw ValidationException::withMessages([
                'new_manager_id' => 'Không thể chọn nhân viên này làm quản lý vì sẽ tạo vòng lặp trong cơ cấu tổ chức.',
            ]);
        }

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

            $employee->forceFill([
                'department_id' => $data['to_department_id'],
                'position_id' => $newPositionId,
                'manager_id' => $data['new_manager_id'] ?? $employee->manager_id,
            ])->save();

            return $transfer;
        });
    }
}
