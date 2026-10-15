<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Quản lý số dư phép (2026-10-15 — quyết định người dùng, tài liệu chỉ yêu cầu "tính toán quỹ
// phép năm"):
//  1) HR điều chỉnh tay (quyền leave.balance_adjust): BẮT BUỘC lý do, lưu lịch sử, báo nhân viên;
//     số dư sau điều chỉnh không được âm.
//  2) Chuyển phép sang năm sau theo chính sách của loại phép (allow_carry_forward,
//     max_carry_forward_days, carry_forward_expiry "MM-DD" — chỉ Admin sửa): số chuyển =
//     min(phép còn dư năm trước, tối đa). Đồng bộ HẰNG NGÀY tới ngày hết hạn (đơn năm cũ duyệt
//     muộn vẫn tính đúng). Phép chuyển được dùng TRƯỚC (hết hạn sớm hơn): qua ngày hết hạn,
//     phần chưa dùng = chuyển − ngày phép đã nghỉ tính tới ngày hết hạn -> mất (ghi lịch sử).
//  3) Nghỉ việc: số ngày phép còn lại được trả vào lương tháng cuối (PayrollService, BLĐ 2019
//     Điều 113 khoản 3). Phép âm (nghỉ quá) KHÔNG trừ lương — chỉ cảnh báo để HR tự thỏa thuận.
// Số dư = allocated + carried_forward + adjusted − used (không đổi công thức cũ): điều chỉnh tay
// và phần hết hạn đều đi vào adjusted_days.
class LeaveBalanceService
{
    public function __construct(
        private readonly LeaveAccrualService $leaveAccrualService,
        private readonly NotificationService $notificationService,
    ) {
    }

    public static function remainingOf(LeaveBalance $balance): float
    {
        return round(
            (float) $balance->allocated_days + (float) $balance->carried_forward_days
            + (float) $balance->adjusted_days - (float) $balance->used_days,
            2,
        );
    }

    /** Ngày hết hạn phép chuyển của năm $year theo chính sách loại phép (null = không hết hạn). */
    public static function carryExpiryDate(LeaveType $leaveType, int $year): ?Carbon
    {
        if (! $leaveType->carry_forward_expiry) {
            return null;
        }
        [$month, $day] = array_map('intval', explode('-', $leaveType->carry_forward_expiry));

        return Carbon::create($year, $month, min($day, Carbon::create($year, $month, 1)->daysInMonth))->endOfDay();
    }

    public function adjust(User $actor, Employee $employee, LeaveType $leaveType, int $year, float $days, string $reason): LeaveBalanceAdjustment
    {
        $adjustment = DB::transaction(function () use ($actor, $employee, $leaveType, $year, $days, $reason) {
            $balance = $this->leaveAccrualService->resolveOrSyncBalance($employee, $leaveType, $year);
            $balance = LeaveBalance::whereKey($balance->id)->lockForUpdate()->first();

            if (self::remainingOf($balance) + $days < 0) {
                throw ValidationException::withMessages([
                    'days' => 'Số dư sau điều chỉnh không được âm (hiện còn '.self::remainingOf($balance).' ngày).',
                ]);
            }

            $balance->increment('adjusted_days', $days);

            return LeaveBalanceAdjustment::create([
                'leave_balance_id' => $balance->id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'year' => $year,
                'type' => LeaveBalanceAdjustment::TYPE_MANUAL,
                'days' => $days,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);
        });

        $user = $employee->user;
        if ($user && $user->id !== $actor->id && $user->status === 'active') {
            $sign = $days > 0 ? '+' : '';
            $this->notificationService->send($user, 'leave.balance_adjusted', 'Số dư phép được điều chỉnh', "{$leaveType->name} năm {$year}: {$sign}{$days} ngày. Lý do: {$reason}", [
                'leave_type_id' => $leaveType->id,
                'year' => $year,
            ]);
        }

        return $adjustment;
    }

    /** Chi tiết số dư + lịch sử của 1 nhân viên trong năm (mọi loại phép có quỹ). */
    public function detail(Employee $employee, int $year): array
    {
        $leaveTypes = LeaveType::active()->where('annual_entitlement_days', '>', 0)->orderBy('name')->get();

        return $leaveTypes->map(function (LeaveType $leaveType) use ($employee, $year) {
            $balance = LeaveBalance::where(['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => $year])->first();
            $allocated = (float) ($balance->allocated_days ?? $this->leaveAccrualService->targetAllocatedDays($employee, $leaveType, $year, now()));
            $expiry = self::carryExpiryDate($leaveType, $year);

            return [
                'leave_type' => ['id' => $leaveType->id, 'name' => $leaveType->name, 'code' => $leaveType->code],
                'year' => $year,
                'allocated_days' => $allocated,
                'carried_forward_days' => (float) ($balance->carried_forward_days ?? 0),
                'carry_expiry' => ((float) ($balance->carried_forward_days ?? 0)) > 0 ? $expiry?->toDateString() : null,
                'carry_expired' => (bool) $balance?->carry_expired_at,
                'adjusted_days' => (float) ($balance->adjusted_days ?? 0),
                'used_days' => (float) ($balance->used_days ?? 0),
                'remaining_days' => $balance ? self::remainingOf($balance) : $allocated,
                'history' => $balance
                    ? $balance->adjustments()->with('creator:id,user_name')->get()->map(fn (LeaveBalanceAdjustment $a) => [
                        'id' => $a->id,
                        'type' => $a->type,
                        'days' => $a->days,
                        'reason' => $a->reason,
                        'creator' => $a->creator?->user_name,
                        'created_at' => $a->created_at,
                    ])->values()->all()
                    : [],
            ];
        })->values()->all();
    }

    /**
     * Đồng bộ phép chuyển năm + xử lý hết hạn (job leave:sync-accrual, hằng giờ). Idempotent.
     *
     * @return array{carried: int, expired: int}
     */
    public function syncCarryForward(?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $year = $asOf->year;
        $carried = 0;
        $expired = 0;

        $leaveTypes = LeaveType::active()->where('allow_carry_forward', true)->where('annual_entitlement_days', '>', 0)->get();
        foreach ($leaveTypes as $leaveType) {
            $expiry = self::carryExpiryDate($leaveType, $year);
            $max = (float) ($leaveType->max_carry_forward_days ?? 0);

            // 1) Trước ngày hết hạn: tính lại số chuyển theo số dư năm trước hiện tại.
            if ($max > 0 && ($expiry === null || $asOf->lte($expiry))) {
                $previous = LeaveBalance::where('leave_type_id', $leaveType->id)->where('year', $year - 1)
                    ->whereHas('employee', fn ($q) => $q->whereIn('employment_status', Employee::WORKING_STATUSES))
                    ->with('employee')->get();

                foreach ($previous as $prev) {
                    $carry = max(0.0, min(self::remainingOf($prev), $max));
                    $balance = $this->leaveAccrualService->resolveOrSyncBalance($prev->employee, $leaveType, $year, $asOf);
                    if (abs((float) $balance->carried_forward_days - $carry) < 0.001) {
                        continue;
                    }

                    DB::transaction(function () use ($balance, $carry, $leaveType, $year, $prev) {
                        $balance->update(['carried_forward_days' => $carry]);
                        // 1 dòng lịch sử duy nhất cho phép chuyển của năm — cập nhật khi số đổi.
                        LeaveBalanceAdjustment::updateOrCreate(
                            ['leave_balance_id' => $balance->id, 'type' => LeaveBalanceAdjustment::TYPE_CARRY_FORWARD],
                            [
                                'employee_id' => $prev->employee_id,
                                'leave_type_id' => $leaveType->id,
                                'year' => $year,
                                'days' => $carry,
                                'reason' => 'Phép còn dư năm '.($year - 1).' (tối đa '.rtrim(rtrim(number_format($leaveType->max_carry_forward_days, 2, '.', ''), '0'), '.').' ngày)',
                            ],
                        );
                    });
                    $carried++;
                }
            }

            // 2) Qua ngày hết hạn: phần phép chuyển chưa dùng bị mất (1 lần).
            if ($expiry !== null && $asOf->gt($expiry)) {
                $balances = LeaveBalance::where('leave_type_id', $leaveType->id)->where('year', $year)
                    ->where('carried_forward_days', '>', 0)->whereNull('carry_expired_at')->get();

                foreach ($balances as $balance) {
                    $usedBeforeExpiry = (float) LeaveRequest::where('employee_id', $balance->employee_id)
                        ->where('leave_type_id', $leaveType->id)
                        ->where('status', 'approved')
                        ->whereBetween('from_date', [Carbon::create($year, 1, 1)->toDateString(), $expiry->toDateString()])
                        ->sum('total_days');
                    $unused = round(max(0.0, (float) $balance->carried_forward_days - $usedBeforeExpiry), 2);

                    DB::transaction(function () use ($balance, $unused, $leaveType, $year, $expiry) {
                        if ($unused > 0) {
                            $balance->decrement('adjusted_days', $unused);
                            LeaveBalanceAdjustment::create([
                                'leave_balance_id' => $balance->id,
                                'employee_id' => $balance->employee_id,
                                'leave_type_id' => $leaveType->id,
                                'year' => $year,
                                'type' => LeaveBalanceAdjustment::TYPE_CARRY_EXPIRED,
                                'days' => -$unused,
                                'reason' => 'Phép chuyển từ năm '.($year - 1).' hết hạn ngày '.$expiry->format('d/m/Y').' mà chưa dùng',
                            ]);
                        }
                        $balance->update(['carry_expired_at' => now()]);
                    });
                    $expired += $unused > 0 ? 1 : 0;
                }
            }
        }

        return ['carried' => $carried, 'expired' => $expired];
    }

    /**
     * Số ngày phép năm còn lại tại ngày nghỉ việc (đã đồng bộ tích lũy tới ngày đó) — dùng cho
     * thanh toán phép chưa nghỉ. Âm = đã nghỉ quá số được hưởng.
     */
    public function remainingOnTermination(Employee $employee, Carbon $terminationDate): float
    {
        $leaveType = LeaveType::active()->where('code', 'annual')->first()
            ?? LeaveType::active()->where('annual_entitlement_days', '>', 0)->first();
        if (! $leaveType) {
            return 0.0;
        }

        $balance = $this->leaveAccrualService->resolveOrSyncBalance($employee, $leaveType, $terminationDate->year, $terminationDate);

        return self::remainingOf($balance->fresh());
    }

    /** @return Collection<int, LeaveType> */
    public function annualLeaveTypes(): Collection
    {
        return LeaveType::where('annual_entitlement_days', '>', 0)->orderBy('name')->get();
    }
}
