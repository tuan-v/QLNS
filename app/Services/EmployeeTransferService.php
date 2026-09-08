<?php

namespace App\Services;

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
        if (
            ! empty($data['new_manager_id'])
            && $this->employeeRepository->wouldCreateCycle($employee->id, $data['new_manager_id'])
        ) {
            throw ValidationException::withMessages([
                'new_manager_id' => 'Không thể chọn nhân viên này làm quản lý vì sẽ tạo vòng lặp trong cơ cấu tổ chức.',
            ]);
        }

        return DB::transaction(function () use ($employee, $data, $approvedBy, $decisionFile) {
            $data['employee_id'] = $employee->id;
            $data['from_department_id'] = $employee->department_id;
            $data['old_position_id'] = $employee->position_id;
            $data['approved_by'] = $approvedBy;
            $data['approved_at'] = now();

            if ($decisionFile) {
                $data['decision_file'] = $decisionFile->store('employee-transfers', 'local');
            }

            $transfer = $this->employeeTransferRepository->create($data);

            $employee->forceFill([
                'department_id' => $data['to_department_id'],
                'position_id' => $data['new_position_id'] ?? $employee->position_id,
                'manager_id' => $data['new_manager_id'] ?? $employee->manager_id,
            ])->save();

            return $transfer;
        });
    }
}
