<?php

namespace App\Services;

use App\Models\WorkShift;
use App\Repositories\WorkShiftRepository;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    // Ca mặc định (2026-09-23) — nhân viên mới tạo tự động được gán ca này
    // (xem EmployeeShiftAssignmentService::assignDefaultShift()), trang
    // "Cài đặt" (Settings.vue) đọc/sửa NGAY bản ghi này.
    public function getDefault(): ?WorkShift
    {
        return WorkShift::default()->first();
    }

    public function create(array $data): WorkShift
    {
        $data['code'] = $this->generateCode();

        return DB::transaction(function () use ($data) {
            if ($data['is_default'] ?? false) {
                $this->clearOtherDefaults(null);
            }

            return $this->workShiftRepository->create($data);
        });
    }

    // "Tự chọn giờ" khi "Xin làm ngoài lịch" (2026-09-23, theo yêu cầu người
    // dùng — xem AttendanceAdjustmentService::requestForEmployee()) — nhân
    // viên không chọn 1 Ca có sẵn mà tự gõ giờ vào/ra, nên tạo NGAY 1 Ca MỚI
    // đúng khung giờ đó để tái dùng TOÀN BỘ cơ chế "unlock 1 ngày" sẵn có
    // (EmployeeShiftAssignmentService::createOneOffAssignment() cần 1
    // WorkShift thật để trỏ tới) — không viết đường riêng cho trường hợp
    // này. Đặt tên rõ ràng để HR nhận ra đây là Ca tự sinh khi duyệt/xem lại
    // trang "Ca làm việc", KHÔNG đánh dấu is_default. Chuẩn giờ chuẩn/châm
    // chước lấy mặc định hợp lý giống nhân viên mới không có gì đặc biệt để
    // dựa vào (5 phút — cùng giá trị demo dùng khắp dự án).
    public function createCustomOneOff(string $startTime, string $endTime): WorkShift
    {
        $standardMinutes = max(1, (int) Carbon::parse($startTime)->diffInMinutes(Carbon::parse($endTime)));

        return $this->create([
            'name' => "Ca tùy chỉnh {$startTime}-{$endTime}",
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_start_time' => null,
            'break_end_time' => null,
            'standard_work_minutes' => $standardMinutes,
            'late_grace_minutes' => 5,
            'early_leave_grace_minutes' => 5,
            'work_coefficient' => 1,
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    // Đúng 1 ca được là mặc định tại 1 thời điểm — đặt ca này làm mặc định
    // thì tự gỡ cờ khỏi MỌI ca khác trước (transaction cha ở create()/update()
    // bọc quanh việc này, xem ở dưới). $excludeId = ca đang lưu (update) để
    // không tự gỡ cờ của chính nó ngay trước khi ghi đè; null khi tạo mới
    // (chưa có id để loại trừ). Lưu từng bản ghi qua save() thường (không
    // dùng bulk query ->update()) để AuditObserver vẫn ghi vết — cùng lý do
    // đã áp dụng ở EmployeeShiftAssignmentService::removeAllForWorkShift().
    private function clearOtherDefaults(?int $excludeId): void
    {
        WorkShift::default()
            ->when($excludeId, fn ($query, $id) => $query->whereKeyNot($id))
            ->get()
            ->each(fn (WorkShift $shift) => $shift->forceFill(['is_default' => false])->save());
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
        $willBeDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : (bool) $workShift->is_default;

        // Ca mặc định mà bị tắt hoạt động thì nhân viên mới tạo sẽ được gán
        // vào 1 ca đã ngừng hoạt động — vô lý, chặn lại (xem
        // assignDefaultShift() chỉ tìm ca vừa is_default vừa is_active).
        if ($workShift->is_default && ! $willBeActive) {
            throw ValidationException::withMessages([
                'is_active' => 'Đây là ca mặc định — hãy đặt ca khác làm mặc định trước khi ngừng hoạt động ca này.',
            ]);
        }

        return DB::transaction(function () use ($workShift, $data, $wasActive, $willBeActive, $willBeDefault) {
            if ($willBeDefault && ! $workShift->is_default) {
                $this->clearOtherDefaults($workShift->id);
            }

            $updated = $this->workShiftRepository->update($workShift, $data);

            if ($wasActive && ! $willBeActive) {
                $this->employeeShiftAssignmentService->removeAllForWorkShift($workShift->id);
            }

            // Trang Cài đặt đổi "ngày làm việc" của Ca mặc định (2026-09-24)
            // — đồng bộ ngay cho mọi nhân viên đang theo ca này. Chỉ chạy
            // khi request THẬT SỰ gửi work_days (Settings.vue) — form đầy đủ
            // ở tab "Ca làm việc" (mục 12) không có ô này nên không vô tình
            // đụng vào các Ca khác.
            if ($willBeDefault && ! empty($data['work_days'])) {
                $this->employeeShiftAssignmentService->resyncOpenEndedWorkDays($workShift->id, $data['work_days']);
            }

            return $updated;
        });
    }

    // 2026-09-24, sửa lỗi thật: xóa Ca làm việc TRƯỚC ĐÂY không gỡ các bản
    // gán ca (EmployeeShiftAssignment) còn đang trỏ tới nó — để lại bản gán
    // "mồ côi" (work_shift_id trỏ tới Ca đã xóa mềm), khiến trang "Tổng quan
    // chấm công"/"Lịch sử chấm công" sập với lỗi "Attempt to read property
    // id on null" khi đọc `$assignment->workShift->id` (quan hệ trả NULL vì
    // Ca đã bị xóa mềm). Gỡ hết bản gán TRƯỚC khi xóa Ca — cùng cách
    // update() đã làm khi tắt is_active (removeAllForWorkShift()).
    public function delete(WorkShift $workShift): void
    {
        if ($workShift->is_default) {
            throw ValidationException::withMessages([
                'is_default' => 'Đây là ca mặc định — hãy đặt ca khác làm mặc định trước khi xóa ca này.',
            ]);
        }

        DB::transaction(function () use ($workShift) {
            $this->employeeShiftAssignmentService->removeAllForWorkShift($workShift->id);
            $this->workShiftRepository->delete($workShift);
        });
    }
}
