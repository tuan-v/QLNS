<?php

namespace App\Services;

use App\Models\WorkShift;
use App\Repositories\WorkShiftRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WorkShiftService
{
    public function __construct(
        private readonly WorkShiftRepository $workShiftRepository,
        private readonly EmployeeShiftAssignmentService $employeeShiftAssignmentService,
    ) {
    }

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->workShiftRepository->paginate(perPage: $perPage);
    }

    public function create(array $data): WorkShift
    {
        $data['code'] = $this->generateCode();

        return DB::transaction(fn () => $this->workShiftRepository->create($data));
    }

    // Mã ca làm việc do hệ thống tự sinh, không nhận từ client: "CA" + số thứ tự
    // 3 chữ số (CA001, CA002...) — cùng cơ chế với PositionService::generateCode().
    private function generateCode(): string
    {
        $prefix = 'CA';

        $lastNumber = WorkShift::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->filter(fn (string $code) => preg_match('/^'.$prefix.'(\d+)$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();

        $nextNumber = ($lastNumber ?? 0) + 1;

        return $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    // Tắt is_active thì tự gỡ TOÀN BỘ bản gán ca đang trỏ tới Ca này khỏi mọi
    // nhân viên — Ca ngừng hoạt động không còn hợp lệ để ai theo nữa.
    // StoreEmployeeShiftAssignmentRequest/UpdateEmployeeShiftAssignmentRequest
    // đã chặn gán MỚI vào Ca đã tắt (mục 14 CODE_MAP); đây là xử lý tiếp cho
    // dữ liệu ĐÃ gán từ trước, lúc Ca còn hoạt động.
    public function update(WorkShift $workShift, array $data): WorkShift
    {
        $wasActive = (bool) $workShift->is_active;
        $willBeActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $wasActive;

        return DB::transaction(function () use ($workShift, $data, $wasActive, $willBeActive) {
            $updated = $this->workShiftRepository->update($workShift, $data);

            if ($wasActive && ! $willBeActive) {
                $this->employeeShiftAssignmentService->removeAllForWorkShift($workShift->id);
            }

            return $updated;
        });
    }

    public function delete(WorkShift $workShift): void
    {
        $this->workShiftRepository->delete($workShift);
    }
}
