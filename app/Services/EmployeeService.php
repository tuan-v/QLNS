<?php

namespace App\Services;

use App\Models\Employee;
use App\Repositories\EmployeeRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function __construct(
        private readonly EmployeeRepository $employeeRepository,
        private readonly EmployeeShiftAssignmentService $employeeShiftAssignmentService,
        private readonly EmployeeContractService $employeeContractService,
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
        unset($data['agreed_salary']);

        return DB::transaction(function () use ($data, $agreedSalary) {
            $employee = $this->employeeRepository->create($data);
            // Ca mặc định (2026-09-23, theo yêu cầu người dùng) — nhân viên
            // mới tạo tự động được gán ca đang đánh dấu is_default=true, HR
            // vẫn đổi/gán thêm ca khác cho họ sau đó ở tab "Ca làm việc" nếu
            // cần. Không báo lỗi gì nếu công ty CHƯA cấu hình ca mặc định
            // (assignDefaultShift() trả về null) — vẫn tạo nhân viên bình
            // thường, chỉ là chưa có ca nào, giống hành vi trước đây.
            $this->employeeShiftAssignmentService->assignDefaultShift($employee);

            // Hợp đồng lao động ĐẦU TIÊN tự tạo LUÔN cùng lúc (2026-09-24,
            // theo yêu cầu người dùng: "điền lương cơ bản vào luôn... không
            // cần tạo hđ như bây giờ") — đã hỏi lại và CHỐT: vẫn giữ nguyên
            // kiến trúc Payroll dựa trên EmployeeContract (đúng tài liệu yêu
            // cầu, xem CODE_MAP), chỉ bỏ bước THAO TÁC THỦ CÔNG riêng của HR.
            // `contract_type` suy từ `employment_status` (active -> chính
            // thức, còn lại/mặc định probation -> thử việc) thay vì hỏi
            // thêm field riêng; `start_date` DÙNG CHUNG `hire_date`, không
            // hỏi lại ngày thứ 2. File hợp đồng đã ký (`contract_file`) CHƯA
            // bắt buộc ở đây — HR upload sau ở tab "Hợp đồng" khi có bản
            // giấy thật. `insurance_salary` KHÔNG cần truyền — cùng ngày
            // (2026-09-24, theo yêu cầu người dùng: "lương đóng bh sẽ tính
            // là lương cb luôn không tách ra") EmployeeContractService::create()
            // tự đặt insurance_salary = agreed_salary cho MỌI hợp đồng.
            // Đọc từ $data (giá trị request gửi lên) chứ không phải
            // $employee->employment_status — EmployeeRepository::create()
            // không gọi ->fresh() nên thuộc tính đó có thể đang NULL trong bộ
            // nhớ dù DB đã tự áp default 'probation' (cùng bẫy đã ghi chú ở
            // EmployeeContractRepository::create()). Không sao vì mọi giá trị
            // KHÁC 'active' (kể cả null/thiếu) đều đúng nghĩa "chưa chính
            // thức" -> rơi về 'thu_viec'.
            $contractType = ($data['employment_status'] ?? 'probation') === 'active' ? 'chinh_thuc' : 'thu_viec';
            $this->employeeContractService->create($employee, [
                'contract_type' => $contractType,
                'start_date' => $employee->hire_date->toDateString(),
                'agreed_salary' => $agreedSalary,
            ]);

            return $employee;
        });
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
        if (
            array_key_exists('manager_id', $data)
            && $data['manager_id'] !== null
            && $this->employeeRepository->wouldCreateCycle($employee->id, $data['manager_id'])
        ) {
            throw ValidationException::withMessages([
                'manager_id' => 'Không thể chọn nhân viên này làm quản lý vì sẽ tạo vòng lặp trong cơ cấu tổ chức.',
            ]);
        }

        return $this->employeeRepository->update($employee, $data);
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
