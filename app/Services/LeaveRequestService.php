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
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    // Quy đổi nghỉ theo giờ sang ngày công — 8 giờ = 1 ngày (giống chuẩn 1
    // ngày làm việc 8 tiếng), không lấy theo work_shift cụ thể của nhân viên
    // để tránh phải phụ thuộc module Ca làm việc (mục 12) chỉ vì tính quy đổi.
    private const HOURS_PER_DAY = 8.0;

    public function __construct(
        private readonly LeaveRequestRepository $leaveRequestRepository,
        private readonly LeaveBalanceRepository $leaveBalanceRepository,
    ) {
    }

    // Quyết định nghiệp vụ đã chốt với người dùng: gửi đơn CHỈ validate còn
    // đủ phép hay không, KHÔNG trừ used_days ở đây — trừ thật diễn ra khi
    // đơn được DUYỆT (Ngày 37, xem deductBalance()).
    public function create(Employee $employee, array $data, ?UploadedFile $evidenceFile = null): LeaveRequest
    {
        $leaveType = LeaveType::findOrFail($data['leave_type_id']);
        $fromDate = Carbon::parse($data['from_date']);
        $toDate = Carbon::parse($data['to_date']);
        $startSession = $data['start_session'] ?? 'full';
        $endSession = $data['end_session'] ?? 'full';
        $isHourly = $startSession === 'hourly';

        // Nghỉ theo giờ chỉ trong 1 ngày (StoreLeaveRequest đã chặn from_date
        // !== to_date) — vẫn phải tự kiểm tra Thứ 7/CN riêng vì
        // calculateHourlyDays() không tự biết ngày nào là cuối tuần (chỉ so
        // giờ), khác calculateTotalDays() đã lo việc này qua CarbonPeriod.
        if ($isHourly && in_array($fromDate->dayOfWeekIso, [6, 7], true)) {
            throw ValidationException::withMessages([
                'to_date' => 'Khoảng thời gian xin nghỉ không có ngày làm việc nào (chỉ rơi vào Thứ 7/Chủ nhật).',
            ]);
        }

        // Quy đổi theo số phút chênh lệch start_time/end_time thay vì đếm
        // ngày như luồng cả ngày/nửa ngày bên dưới.
        $totalDays = $isHourly
            ? $this->calculateHourlyDays($fromDate, $data['start_time'], $data['end_time'])
            : $this->calculateTotalDays($fromDate, $toDate, $startSession, $endSession);

        if ($totalDays <= 0) {
            throw ValidationException::withMessages([
                'to_date' => 'Khoảng thời gian xin nghỉ không có ngày làm việc nào (chỉ rơi vào Thứ 7/Chủ nhật).',
            ]);
        }

        // Chặn đơn phép trùng ngày với 1 đơn khác CHƯA bị từ chối (mọi loại
        // phép, không riêng loại đang xin) — xác nhận với người dùng khi
        // chuẩn bị làm Ngày 41: không chặn thì cùng 1 ngày có thể được duyệt
        // 2 lần ở 2 đơn khác nhau, cộng dồn used_days sai và bảng chấm công
        // tổng hợp (Ngày 41) không biết chọn đơn nào để hiển thị.
        $overlapping = $this->leaveRequestRepository->findOverlapping($employee, $fromDate->toDateString(), $toDate->toDateString());

        if ($overlapping) {
            throw ValidationException::withMessages([
                'from_date' => "Đã có đơn nghỉ phép khác trùng ngày (từ {$overlapping->from_date->format('d/m/Y')} đến {$overlapping->to_date->format('d/m/Y')}).",
            ]);
        }

        $balance = $this->leaveBalanceRepository->findOrCreateForYear($employee, $leaveType, $fromDate->year);

        // Loại phép annual_entitlement_days = 0 (ốm, thai sản, không lương,
        // nghỉ khác...) KHÔNG bị giới hạn theo quỹ ngày — tính theo chế độ
        // riêng (BHXH, không lương), chỉ "Nghỉ phép năm" (entitlement > 0)
        // mới thật sự có quỹ để chặn khi vượt. Vẫn tạo/giữ dòng leave_balances
        // ở trên (không bỏ qua) để deductBalance() sau này (lúc duyệt) luôn
        // tìm được dòng cần cộng used_days, dù remaining không bị kiểm tra ở đây.
        if ($leaveType->annual_entitlement_days > 0) {
            $remaining = $this->remainingDays($balance);

            // used_days chỉ cộng lúc DUYỆT xong (không phải lúc tạo, xem Ghi
            // chú ở trên) — nếu chỉ so total_days với remaining thì nhân
            // viên gửi được NHIỀU đơn pending cộng dồn vượt quá 12 ngày, vì
            // đơn nào cũng thấy "còn nguyên 12" (chưa đơn nào bị trừ). Phải
            // trừ thêm tổng ngày của các đơn CÙNG loại phép đang pending/
            // manager_approved (chưa có kết quả cuối) mới ra đúng số còn
            // "khả dụng để đăng ký thêm" — xác nhận với người dùng.
            $pendingDays = $this->leaveRequestRepository->sumPendingDaysForYear($employee->id, $leaveType->id, $fromDate->year);
            $availableDays = round($remaining - $pendingDays, 2);

            if ($totalDays > $availableDays) {
                throw ValidationException::withMessages([
                    'to_date' => $pendingDays > 0
                        ? "Không đủ số ngày phép khả dụng (còn {$availableDays} ngày — đã trừ {$pendingDays} ngày đang chờ duyệt ở đơn khác, xin nghỉ {$totalDays} ngày)."
                        : "Không đủ số ngày phép còn lại (còn {$availableDays} ngày, xin nghỉ {$totalDays} ngày).",
                ]);
            }
        }

        // Giấy tờ đính kèm (khám bệnh, chứng sinh...) — riêng tư, nên lưu
        // disk 'local' (không public), giống EmployeeDocumentService/
        // EmployeeContractService. StoreLeaveRequest đã bắt buộc có file cho
        // ốm/thai sản/chế độ cha-mẹ, các loại khác thì tùy chọn.
        $evidencePath = $evidenceFile?->store('leave-evidence', 'local');

        return $this->leaveRequestRepository->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'start_session' => $startSession,
            'end_session' => $endSession,
            'start_time' => $isHourly ? $data['start_time'] : null,
            'end_time' => $isHourly ? $data['end_time'] : null,
            'total_days' => $totalDays,
            'reason' => $data['reason'],
            'evidence_file_path' => $evidencePath,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->leaveRequestRepository->listForEmployee($employee);
    }

    // Quỹ phép còn lại của nhân viên, theo TỪNG loại phép đang active, cho
    // đúng năm truyền vào (mặc định năm hiện tại). Chỉ ĐỌC — không tự tạo
    // LeaveBalance cho loại phép chưa từng dùng tới (khác findOrCreateForYear()
    // ở create()/deductBalance()) để tránh sinh rác dữ liệu chỉ vì xem trang;
    // chưa có dòng nào thì coi allocated_days = leave_type.annual_entitlement_days,
    // các cột còn lại = 0.
    public function listBalancesForEmployee(Employee $employee, ?int $year = null): Collection
    {
        $year ??= now()->year;

        $balancesByLeaveType = LeaveBalance::where('employee_id', $employee->id)
            ->where('year', $year)
            ->get()
            ->keyBy('leave_type_id');

        return LeaveType::active()->orderBy('name')->get()->map(function (LeaveType $leaveType) use ($balancesByLeaveType, $employee, $year) {
            $balance = $balancesByLeaveType->get($leaveType->id);
            $allocated = (float) ($balance->allocated_days ?? $leaveType->annual_entitlement_days);
            $carriedForward = (float) ($balance->carried_forward_days ?? 0);
            $adjusted = (float) ($balance->adjusted_days ?? 0);
            $used = (float) ($balance->used_days ?? 0);
            $remaining = round($allocated + $carriedForward + $adjusted - $used, 2);
            $pending = $this->leaveRequestRepository->sumPendingDaysForYear($employee->id, $leaveType->id, $year);

            return [
                'leave_type' => $leaveType,
                'year' => $year,
                'allocated_days' => $allocated,
                'carried_forward_days' => $carriedForward,
                'adjusted_days' => $adjusted,
                'used_days' => $used,
                // Đang chờ duyệt (pending/manager_approved) — CHƯA cộng vào
                // used_days (chỉ cộng lúc duyệt xong), nhưng phải trừ ra khỏi
                // "khả dụng" để không cho đăng ký chồng vượt quỹ (xem create()).
                'pending_days' => $pending,
                'remaining_days' => $remaining,
                'available_days' => round($remaining - $pending, 2),
            ];
        });
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->leaveRequestRepository->paginate(perPage: $perPage, filters: $filters);
    }

    // Gọi khi 1 đơn được DUYỆT (Ngày 37) — cộng total_days vào used_days của
    // đúng năm+loại phép, bọc lockForUpdate() chống race khi duyệt đồng thời.
    // create() đã trừ "pending_days" khi kiểm tra lúc tạo đơn (xem Ghi chú ở
    // đó) nên 2 đơn pending cùng employee+loại phép cộng dồn KHÔNG còn vượt
    // quỹ ở trường hợp thường gặp — rủi ro còn lại chỉ là race hiếm khi 2
    // request TẠO ĐƠN xảy ra gần như đồng thời (đọc cùng lúc pending_days
    // trước khi cái nào commit), chưa xử lý (chấp nhận, xác suất rất thấp).
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

    // Nghỉ theo giờ trong đúng 1 ngày — quy đổi số phút chênh lệch
    // start_time/end_time sang "ngày công" theo HOURS_PER_DAY (8 giờ = 1
    // ngày). Không kiểm tra start_time/end_time có nằm trong ca làm việc
    // thật của nhân viên hay không — chấp nhận đơn giản hoá ở bước đầu.
    private function calculateHourlyDays(Carbon $fromDate, string $startTime, string $endTime): float
    {
        $start = Carbon::parse($fromDate->toDateString().' '.$startTime);
        $end = Carbon::parse($fromDate->toDateString().' '.$endTime);

        return round($start->diffInMinutes($end) / 60 / self::HOURS_PER_DAY, 2);
    }
}
