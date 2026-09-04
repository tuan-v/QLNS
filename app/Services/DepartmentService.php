<?php

namespace App\Services;

use App\Models\Department;
use App\Repositories\DepartmentRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepartmentService
{
    public function __construct(private readonly DepartmentRepository $departmentRepository)
    {
    }

    public function list(): LengthAwarePaginator
    {
        return $this->departmentRepository->paginate();
    }

    public function create(array $data): Department
    {
        $data['code'] = $this->generateCode();

        return DB::transaction(fn () => $this->departmentRepository->create($data));
    }

    // Mã phòng ban do hệ thống tự sinh, không nhận từ client: "PB" + số thứ tự
    // 3 chữ số (PB001, PB002...), tính tiếp từ mã lớn nhất đang có (kể cả đã xóa mềm,
    // vì cột code vẫn giữ ràng buộc unique với các bản ghi đã xóa mềm).
    // Chỉ tính các mã đúng khớp mẫu "PB" + toàn chữ số — bỏ qua mã tự nhập tay
    // trước đây (vd "PB-01") để không bị đọc nhầm phần đuôi thành số âm.
    private function generateCode(): string
    {
        $prefix = 'PB';

        $lastNumber = Department::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->filter(fn (string $code) => preg_match('/^'.$prefix.'(\d+)$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();

        $nextNumber = ($lastNumber ?? 0) + 1;

        return $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    public function update(Department $department, array $data): Department
    {
        if (array_key_exists('parent_id', $data)
            && $this->departmentRepository->wouldCreateCycle($department->id, $data['parent_id'])) {
            throw ValidationException::withMessages([
                'parent_id' => 'Không thể chọn phòng ban này làm cha vì sẽ tạo vòng lặp trong cơ cấu tổ chức.',
            ]);
        }

        return DB::transaction(fn () => $this->departmentRepository->update($department, $data));
    }

    public function delete(Department $department): void
    {
        if ($department->children()->exists()) {
            throw ValidationException::withMessages([
                'department' => 'Không thể xóa phòng ban đang có phòng ban trực thuộc.',
            ]);
        }

        $this->departmentRepository->delete($department);
    }
    public function tree(): \Illuminate\Support\Collection
    {
        return $this->departmentRepository->tree();
    }


}
