<?php

namespace App\Services;

use App\Models\AttendanceLocation;
use App\Repositories\AttendanceLocationRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttendanceLocationService
{
    public function __construct(private readonly AttendanceLocationRepository $attendanceLocationRepository)
    {
    }

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->attendanceLocationRepository->paginate(perPage: $perPage);
    }

    public function create(array $data): AttendanceLocation
    {
        $data['code'] = $this->generateCode();

        // qr_secret không nhận từ client — tự sinh chuỗi ngẫu nhiên khi chọn
        // phương thức QR, cùng cơ chế Str::random() với token quên mật khẩu
        // (PasswordResetService) — không tự nghĩ cách mới.
        if ($data['method'] === 'qr') {
            $data['qr_secret'] = Str::random(64);
        }

        return DB::transaction(fn () => $this->attendanceLocationRepository->create($data));
    }

    // Mã điểm chấm công do hệ thống tự sinh, không nhận từ client: "DD" + số
    // thứ tự 3 chữ số (DD001, DD002...) — cùng cơ chế với WorkShiftService/
    // PositionService::generateCode().
    private function generateCode(): string
    {
        $prefix = 'DD';

        $lastNumber = AttendanceLocation::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->filter(fn (string $code) => preg_match('/^'.$prefix.'(\d+)$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();

        $nextNumber = ($lastNumber ?? 0) + 1;

        return $prefix.str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    public function update(AttendanceLocation $attendanceLocation, array $data): AttendanceLocation
    {
        // Đổi sang phương thức QR mà trước đó chưa có secret (vd tạo lúc đầu
        // bằng wifi/gps rồi sửa lại) thì sinh mới; đã có rồi thì giữ nguyên,
        // không sinh lại (đổi bí mật ngầm sẽ làm hỏng QR đã in/phát ra trước đó).
        if ($data['method'] === 'qr' && ! $attendanceLocation->qr_secret) {
            $data['qr_secret'] = Str::random(64);
        }

        return $this->attendanceLocationRepository->update($attendanceLocation, $data);
    }

    public function delete(AttendanceLocation $attendanceLocation): void
    {
        $this->attendanceLocationRepository->delete($attendanceLocation);
    }
}
