<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Position;
use App\Repositories\PositionRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PositionService
{
    public function __construct(private readonly PositionRepository $positionRepository)
    {
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->positionRepository->paginate(perPage: $perPage, filters: $filters);
    }

    public function create(array $data): Position
    {
        $data['code'] = $this->generateCode();

        return DB::transaction(fn () => $this->positionRepository->create($data));
    }

    // Mã chức vụ do hệ thống tự sinh, không nhận từ client: "CV" + số thứ tự
    // 3 chữ số (CV001, CV002...) — cùng cơ chế với DepartmentService::generateCode()
    // và EmployeeService::generateCode() (bỏ qua mã cũ không đúng mẫu, tính cả
    // bản ghi đã xóa mềm vì cột code vẫn giữ ràng buộc unique với chúng).
    private function generateCode(): string
    {
        $prefix = 'CV';

        $lastNumber = Position::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->filter(fn (string $code) => preg_match('/^'.$prefix.'(\d+)$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();

        $nextNumber = ($lastNumber ?? 0) + 1;

        return $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    public function update(Position $position, array $data): Position
    {
        return $this->positionRepository->update($position, $data);
    }

    public function delete(Position $position): void
    {
        $this->positionRepository->delete($position);
    }

    // "Trưởng phòng" của 1 phòng ban — do hệ thống tự quản lý (type='head'),
    // không cho sửa tay qua CRUD Chức vụ thường. Mỗi phòng ban tối đa 1 bản ghi
    // loại này, tự tạo lúc lần đầu có ai được gán làm Trưởng phòng.
    public function ensureHeadPosition(Department $department): Position
    {
        return Position::firstOrCreate(
            ['department_id' => $department->id, 'type' => 'head'],
            ['code' => $this->generateCode(), 'name' => 'Trưởng phòng', 'is_active' => true],
        );
    }

    // "Nhân viên" mặc định của 1 phòng ban — nơi hạ chức vụ Trưởng phòng cũ về
    // khi bị thay thế/điều chuyển đi nơi khác, cùng cơ chế find-or-create như
    // ensureHeadPosition() ở trên.
    public function ensureDefaultPosition(Department $department): Position
    {
        return Position::firstOrCreate(
            ['department_id' => $department->id, 'type' => 'default'],
            ['code' => $this->generateCode(), 'name' => 'Nhân viên', 'is_active' => true],
        );
    }
}
