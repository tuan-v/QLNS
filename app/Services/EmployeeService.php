<?php

namespace App\Services;

use App\Models\Employee;
use App\Repositories\EmployeeRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmployeeService
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly EmployeeShiftAssignmentService $employeeShiftAssignmentService,
        private readonly EmployeeContractService $employeeContractService,
        private readonly ReportingLineService $reportingLineService,
        private readonly EmployeeAccountService $employeeAccountService,
        private readonly EmployeeTransferService $employeeTransferService,
        private readonly RecruitmentService $recruitmentService,
        private readonly ChecklistService $checklistService,
    ) {
    }
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->employeeRepository->paginate(perPage: $perPage, filters: $filters);
    }

    // Đếm nhanh cho 4 thẻ thống kê đầu trang Employees.vue — chỉ đếm theo
    // employment_status đang có thật trong DB, không có "đang nghỉ phép" (đó
    // là trạng thái tạm thời theo ngày, thuộc module Nghỉ phép chưa xây).
    public function stats(): array
    {
        $counts = $this->employeeRepository->countByStatus();

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) $counts->get('active', 0),
            'probation' => (int) $counts->get('probation', 0),
            'intern' => (int) $counts->get('intern', 0),
            'resigned' => (int) $counts->get('resigned', 0),
        ];
    }
    public function create(array $data): Employee
    {
        $data['code'] = $this->generateCode();
        // Tách riêng field lương ra khỏi $data trước khi tạo Employee —
        // Employee model KHÔNG có cột này (dùng để tạo Hợp đồng ngay bên
        // dưới), giữ trong $data thì Eloquent mass-assignment cũng tự bỏ qua
        // (không nằm trong $fillable) nhưng tách ra cho rõ ràng, tránh hiểu
        // nhầm sau này khi đọc lại.
        $agreedSalary = $data['agreed_salary'];
        $contractType = $data['contract_type'];
        unset($data['agreed_salary'], $data['contract_type']);
        // Trạng thái nhân viên theo ĐÚNG loại hợp đồng đầu tiên (2026-09-29,
        // theo yêu cầu người dùng — trước đó HR chọn tay trạng thái rồi hợp
        // đồng suy ngược từ trạng thái). Từ đây trở đi trạng thái chỉ đổi qua
        // hợp đồng/đơn nghỉ việc, xem EmployeeContractService::applyEmploymentStatus().
        $data['employment_status'] = EmployeeContractService::EMPLOYMENT_STATUS_BY_CONTRACT_TYPE[$contractType];

        // Quản lý trực tiếp luôn tự suy ra từ phòng ban (ReportingLineService),
        // không nhận từ client.
        unset($data['manager_id']);

        // Tạo từ ứng viên tuyển dụng ("Nhận việc"): đánh dấu ứng viên đã nhận việc trong
        // CÙNG transaction — lỗi ở bước nào thì cả nhân viên lẫn ứng viên đều không đổi.
        $candidateId = $data['candidate_id'] ?? null;
        unset($data['candidate_id']);

        $employee = DB::transaction(function () use ($data, $agreedSalary, $contractType, $candidateId) {
            $employee = $this->employeeRepository->create($data);
            if ($candidateId !== null) {
                $this->recruitmentService->markHired((int) $candidateId, $employee);
            }
            $this->reportingLineService->syncEmployee($employee);
            // Dòng đầu của lịch sử luân chuyển: vào phòng ban nào, chức vụ gì, từ ngày nào.
            $this->employeeTransferService->recordOnboarding($employee);
            // Ca mặc định (2026-09-23, theo yêu cầu người dùng) — nhân viên
            // mới tạo tự động được gán ca đang đánh dấu is_default=true, HR
            // vẫn đổi/gán thêm ca khác cho họ sau đó ở tab "Ca làm việc" nếu
            // cần. Không báo lỗi gì nếu công ty CHƯA cấu hình ca mặc định
            // (assignDefaultShift() trả về null) — vẫn tạo nhân viên bình
            // thường, chỉ là chưa có ca nào, giống hành vi trước đây.
            $this->employeeShiftAssignmentService->assignDefaultShift($employee);

            // Hợp đồng lao động ĐẦU TIÊN tự tạo LUÔN cùng lúc (2026-09-24,
            // theo yêu cầu người dùng: "điền lương cơ bản vào luôn... không
            // cần tạo hđ như bây giờ") — vẫn giữ kiến trúc Payroll dựa trên
            // EmployeeContract, chỉ bỏ bước thao tác thủ công riêng của HR.
            // Loại hợp đồng do HR chọn ở form (2026-09-29). `start_date` DÙNG
            // CHUNG `hire_date`. File hợp đồng đã ký (`contract_file`) CHƯA
            // bắt buộc ở đây — HR upload sau ở tab "Hợp đồng".
            $this->employeeContractService->create($employee, [
                'contract_type' => $contractType,
                'start_date' => $employee->hire_date->toDateString(),
                'agreed_salary' => $agreedSalary,
            ]);

            // Checklist nhận việc theo mẫu mặc định (không có mẫu nào đang dùng thì bỏ qua).
            $this->checklistService->startOnboarding($employee);

            return $employee;
        });


        return $employee;
    }
    private function generateCode(): string
    {
        $prefix = 'NV';
        $lastNumber = Employee::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->pluck('code')
            ->filter(fn (string $code) => preg_match('/^' . $prefix . '(\d+)$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();
        $nextNumber = ($lastNumber ?? 0) + 1;
        return $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }
    public function update(Employee $employee, array $data): Employee
    {
        // Không nhận manager_id từ client — tự tính lại theo phòng ban (đổi
        // phòng ban ở form Sửa cũng tự đổi luôn quản lý).
        unset($data['manager_id']);

        $oldDepartmentId = $employee->department_id;
        $oldPositionId = $employee->position_id;

        $employee = DB::transaction(function () use ($employee, $data, $oldDepartmentId, $oldPositionId) {
            $employee = $this->employeeRepository->update($employee, $data);
            $this->reportingLineService->syncEmployee($employee);
            // Đổi phòng ban/chức vụ ngay trong form Sửa vẫn phải có trong lịch sử luân chuyển.
            $this->employeeTransferService->recordAdjustment(
                $employee,
                $oldDepartmentId,
                $oldPositionId,
                'Cập nhật phòng ban/chức vụ trong hồ sơ nhân viên',
            );

            return $employee;
        });


        return $employee;
    }

    // 2026-09-24, cùng lý do đã sửa ở WorkShiftService::delete() — xóa nhân
    // viên mà không gỡ bản gán ca (EmployeeShiftAssignment) trước sẽ để lại
    // bản gán "mồ côi" theo chiều ngược lại (employee_id trỏ tới nhân viên
    // đã xóa mềm), cùng nguy cơ sập trang chấm công.
    public function delete(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $this->employeeShiftAssignmentService->removeAllForEmployee($employee);
            $this->employeeRepository->delete($employee);
        });

        // Hồ sơ đã xóa thì tài khoản đăng nhập của họ cũng bị khóa.
        $this->employeeAccountService->deactivateAccountOf($employee);

    }


    public function updateAvatar(Employee $employee, UploadedFile $file): Employee
    {
        $oldPath = $employee->avatar;

        $path = $file->store('avatars', 'public');
        $this->employeeRepository->update($employee, ['avatar' => $path]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $employee;
    }
}
