<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Repositories\LeaveBalanceRepository;
use App\Repositories\LeaveRequestRepository;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    public function __construct(
        private readonly LeaveRequestRepository $leaveRequestRepository,
        private readonly LeaveBalanceRepository $leaveBalanceRepository,
    ) {
    }

    // Quyết định nghiệp vụ đã chốt với người dùng: gửi đơn CHỈ validate còn
    // đủ phép hay không, KHÔNG trừ used_days ở đây — trừ thật diễn ra khi
    // đơn được DUYỆT (Ngày 37, xem deductBalance()).
    public function create(Employee $employee, array $data): LeaveRequest
    {
        $leaveType = LeaveType::findOrFail($data['leave_type_id']);
        $fromDate = Carbon::parse($data['from_date']);
        $toDate = Carbon::parse($data['to_date']);
        $startSession = $data['start_session'] ?? 'full';
        $endSession = $data['end_session'] ?? 'full';

        $totalDays = $this->calculateTotalDays($fromDate, $toDate, $startSession, $endSession);

        if ($totalDays <= 0) {
            throw ValidationException::withMessages([
                'to_date' => 'Khoảng thời gian xin nghỉ không có ngày làm việc nào (chỉ rơi vào Thứ 7/Chủ nhật).',
            ]);
        }

        $balance = $this->leaveBalanceRepository->findOrCreateForYear($employee, $leaveType, $fromDate->year);
        $remaining = $this->remainingDays($balance);

        if ($totalDays > $remaining) {
            throw ValidationException::withMessages([
                'to_date' => "Không đủ số ngày phép còn lại (còn {$remaining} ngày, xin nghỉ {$totalDays} ngày).",
            ]);
        }

        return $this->leaveRequestRepository->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'start_session' => $startSession,
            'end_session' => $endSession,
            'total_days' => $totalDays,
            'reason' => $data['reason'],
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->leaveRequestRepository->listForEmployee($employee);
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->leaveRequestRepository->paginate(perPage: $perPage, filters: $filters);
    }

    // Gọi khi 1 đơn được DUYỆT (Ngày 37) — cộng total_days vào used_days của
    // đúng năm+loại phép, bọc lockForUpdate() chống race khi duyệt đồng thời.
    // LƯU Ý cho Ngày 37: phải tính lại "remaining" và so với total_days NGAY
    // TRƯỚC khi gọi hàm này — Ngày 36 chỉ validate lúc TẠO đơn, 2 đơn pending
    // cùng employee+loại phép có thể cùng "đủ phép" tại thời điểm tạo rồi
    // sau đó CẢ HAI đều được duyệt, cộng dồn used_days vượt quỹ nếu không
    // kiểm tra lại ở bước duyệt.
    public function deductBalance(LeaveRequest $leaveRequest): void
    {
        DB::transaction(function () use ($leaveRequest) {
            $balance = $this->leaveBalanceRepository->findForYearLocked(
                $leaveRequest->employee_id,
                $leaveRequest->leave_type_id,
                $leaveRequest->from_date->year,
            );

            $balance->increment('used_days', $leaveRequest->total_days);
        });
    }

    private function remainingDays(LeaveBalance $balance): float
    {
        return round(
            (float) $balance->allocated_days
            + (float) $balance->carried_forward_days
            + (float) $balance->adjusted_days
            - (float) $balance->used_days,
            2
        );
    }

    // Đếm ngày LÀM VIỆC trong khoảng from-to, bỏ qua Thứ 7/Chủ nhật (nghỉ cố
    // định của công ty, không tính vào phép năm — xác nhận với người dùng
    // khi làm Ngày 36). Ngày đầu/cuối tính 0.5 nếu session không phải 'full'.
    private function calculateTotalDays(Carbon $fromDate, Carbon $toDate, string $startSession, string $endSession): float
    {
        $total = 0.0;

        foreach (CarbonPeriod::create($fromDate, $toDate) as $date) {
            if (in_array($date->dayOfWeekIso, [6, 7], true)) {
                continue;
            }

            if ($fromDate->isSameDay($toDate)) {
                $total += $startSession === 'full' ? 1.0 : 0.5;
            } elseif ($date->isSameDay($fromDate) && $startSession !== 'full') {
                $total += 0.5;
            } elseif ($date->isSameDay($toDate) && $endSession !== 'full') {
                $total += 0.5;
            } else {
                $total += 1.0;
            }
        }

        return round($total, 2);
    }
}
