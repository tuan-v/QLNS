<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Employee;
use App\Models\WorkShift;
use App\Repositories\AttendanceAdjustmentRepository;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceAdjustmentService
{
    public function __construct(
        private readonly AttendanceAdjustmentRepository $attendanceAdjustmentRepository,
        private readonly AttendanceService $attendanceService,
    ) {
    }

    // 2 loại: 'correction' (sửa 1 bản ghi attendances ĐÃ TỒN TẠI — hành vi
    // cũ) và 'supplement' (bổ sung chấm công cho 1 ca+ngày CHƯA từng có bản
    // ghi nào, vd nhân viên quên chấm công cả ngày). employee_id/
    // work_shift_id/attendance_date luôn được set trên adjustment cho CẢ 2
    // loại (copy từ attendance nếu correction) để nơi đọc (HR review,
    // listForEmployee) không cần phân nhánh theo type.
    public function requestForEmployee(Employee $employee, array $data, int $requestedBy): AttendanceAdjustment
    {
        $type = $data['type'] ?? 'correction';
        $data['type'] = $type;
        $data['employee_id'] = $employee->id;

        if ($type === 'supplement') {
            $workShift = WorkShift::find($data['work_shift_id']);
            $date = Carbon::parse($data['attendance_date']);

            if (! $workShift || ! $this->attendanceService->resolveActiveAssignment($employee, $workShift, $date)) {
                throw ValidationException::withMessages([
                    'work_shift_id' => 'Bạn không được gán ca này vào ngày đã chọn.',
                ]);
            }

            $alreadyExists = Attendance::where('employee_id', $employee->id)
                ->where('work_shift_id', $workShift->id)
                ->where('attendance_date', $date->toDateString())
                ->exists();

            if ($alreadyExists) {
                throw ValidationException::withMessages([
                    'attendance_date' => 'Đã có bản ghi chấm công cho ca này, vui lòng dùng "Xin điều chỉnh" thay vì "Bổ sung".',
                ]);
            }
        } else {
            $attendance = Attendance::find($data['attendance_id']);

            if (! $attendance || $attendance->employee_id !== $employee->id) {
                throw ValidationException::withMessages([
                    'attendance_id' => 'Bản ghi chấm công không tồn tại hoặc không thuộc về bạn.',
                ]);
            }

            $data['work_shift_id'] = $attendance->work_shift_id;
            $data['attendance_date'] = $attendance->attendance_date;
        }

        $data['requested_by'] = $requestedBy;
        $data['status'] = 'pending';

        return $this->attendanceAdjustmentRepository->create($data);
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->attendanceAdjustmentRepository->listForEmployee($employee);
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->attendanceAdjustmentRepository->paginate(perPage: $perPage, filters: $filters);
    }

    // status: 'approved' | 'rejected'. Duyệt thì áp dụng luôn giờ đề xuất vào
    // Attendance gốc (AttendanceService::applyAdjustment(), tái dùng công
    // thức tính trễ/sớm/giờ công) — từ chối thì chỉ ghi nhận quyết định,
    // không đụng gì tới Attendance.
    public function decide(AttendanceAdjustment $adjustment, string $status, ?string $decisionNote, int $approvedBy): AttendanceAdjustment
    {
        if ($adjustment->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Yêu cầu này đã được xử lý trước đó, không thể duyệt lại.',
            ]);
        }

        return DB::transaction(function () use ($adjustment, $status, $decisionNote, $approvedBy) {
            $adjustment->forceFill([
                'status' => $status,
                'decision_note' => $decisionNote,
                'approved_by' => $approvedBy,
                'decided_at' => now(),
            ])->save();

            if ($status === 'approved') {
                if ($adjustment->type === 'supplement') {
                    $attendance = $this->attendanceService->findOrCreateAttendanceForShift(
                        $adjustment->employee,
                        $adjustment->workShift,
                        $adjustment->attendance_date->toDateString(),
                    );
                    // Ghi lại liên kết — trước khi duyệt, attendance_id vẫn
                    // null vì bản ghi này chưa từng tồn tại.
                    $adjustment->attendance_id = $attendance->id;
                    $adjustment->save();
                } else {
                    $attendance = $adjustment->attendance;
                }

                $this->attendanceService->applyAdjustment(
                    $attendance,
                    $adjustment->proposed_check_in_at,
                    $adjustment->proposed_check_out_at,
                );
            }

            return $adjustment;
        });
    }
}
