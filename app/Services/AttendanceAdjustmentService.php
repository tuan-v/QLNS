<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkShift;
use App\Repositories\AttendanceAdjustmentRepository;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceAdjustmentService
{
    // Số ngày liên tiếp tối đa cho 1 đơn "làm ngoài lịch".
    private const EXTRA_SHIFT_MAX_DAYS = 31;

    private const TYPE_LABELS = [
        'correction' => 'điều chỉnh công',
        'supplement' => 'bổ sung chấm công',
        'excuse' => 'miễn trừ đi muộn',
        'overtime' => 'duyệt OT',
        'extra_shift' => 'làm ngoài lịch',
        'overtime_shift' => 'làm OT ngày khác',
    ];

    public function __construct(
        private readonly AttendanceAdjustmentRepository $attendanceAdjustmentRepository,
        private readonly AttendanceService $attendanceService,
        private readonly EmployeeShiftAssignmentService $employeeShiftAssignmentService,
        private readonly WorkShiftService $workShiftService,
        private readonly NotificationService $notificationService,
    ) {
    }

    // 5 loại: 'correction' (sửa 1 bản ghi attendances ĐÃ TỒN TẠI — hành vi
    // cũ), 'supplement' (bổ sung chấm công cho 1 ca+ngày CHƯA từng có bản
    // ghi nào, vd nhân viên quên chấm công cả ngày), 'excuse' (Ngày 42 —
    // xin miễn trừ đi muộn, chỉ hợp lệ khi bản ghi ĐANG bị tính trễ),
    // 'overtime' (2026-09-21 — xin duyệt OT; ban đầu chỉ hợp lệ SAU khi đã
    // chấm công ra và overtime_minutes>0, 2026-09-23 nới thêm cho phép xin
    // TRƯỚC ngay khi còn đang trong ca — xem nhánh riêng bên dưới;
    // PayrollService chỉ trả lương OT cho bản ghi đã overtime_approved=true,
    // xem calculateWorkedMetrics()), và 'extra_shift' (2026-09-23, theo yêu
    // cầu người dùng — "xin làm OT/làm thêm ngày/làm bù T7-CN không có
    // trong lịch", ĐĂNG KÝ TRƯỚC cho 1 ngày/ca KHÔNG có trong lịch gán —
    // chọn 1 Ca có sẵn HOẶC tự gõ giờ vào/ra (tự tạo 1 Ca mới đúng khung giờ
    // đó, xem WorkShiftService::createCustomOneOff()) — khác 'supplement'
    // vốn dành cho ca ĐÃ được gán mà quên chấm công — xem nhánh riêng bên
    // dưới và decide()/EmployeeShiftAssignmentService::createOneOffAssignment()).
    // employee_id/work_shift_id/attendance_date luôn được set trên
    // adjustment cho MỌI loại (copy từ attendance nếu correction/excuse/
    // overtime) để nơi đọc (HR review, listForEmployee) không cần phân
    // nhánh theo type.
    public function requestForEmployee(Employee $employee, array $data, int $requestedBy): AttendanceAdjustment
    {
        $type = $data['type'] ?? 'correction';
        $data['type'] = $type;
        $data['employee_id'] = $employee->id;

        if ($type === 'overtime_shift') {
            // Khung giờ OT lưu vào 2 cột giờ đề xuất có sẵn; ca OT chỉ tạo khi HR duyệt.
            $data['proposed_check_in_at'] = $data['attendance_date'].' '.$data['custom_start_time'].':00';
            $data['proposed_check_out_at'] = $data['attendance_date'].' '.$data['custom_end_time'].':00';
            $data['work_shift_id'] = null;

            $this->assertOvertimeShiftRequestIsValid($employee, Carbon::parse($data['attendance_date']));
        } elseif ($type === 'extra_shift') {
            // "Tự chọn giờ" (2026-09-23, theo yêu cầu người dùng) — không
            // chọn 1 Ca có sẵn mà tự gõ giờ vào/ra, nên tạo NGAY 1 Ca MỚI
            // đúng khung giờ đó rồi xử lý TIẾP HỆT như đã chọn Ca có sẵn
            // (validate + sau này duyệt) — không viết đường riêng.
            if (empty($data['work_shift_id']) && ! empty($data['custom_start_time']) && ! empty($data['custom_end_time'])) {
                $data['work_shift_id'] = $this->workShiftService
                    ->createCustomOneOff($data['custom_start_time'], $data['custom_end_time'])
                    ->id;
            }

            // Khoảng trùng 1 ngày thì coi như đăng ký 1 ngày (cột để NULL).
            if (empty($data['attendance_date_to']) || $data['attendance_date_to'] === $data['attendance_date']) {
                $data['attendance_date_to'] = null;
            }

            $this->assertExtraShiftRequestIsValid($employee, $data);
        } elseif ($type === 'supplement') {
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

            if ($type === 'excuse') {
                if ($attendance->late_minutes <= 0) {
                    throw ValidationException::withMessages([
                        'attendance_id' => 'Bản ghi này không bị tính đi muộn, không thể xin miễn trừ.',
                    ]);
                }

                if ($attendance->late_excused) {
                    throw ValidationException::withMessages([
                        'attendance_id' => 'Bản ghi này đã được miễn trừ đi muộn trước đó.',
                    ]);
                }
            }

            if ($type === 'overtime') {
                // 2026-09-23 (theo yêu cầu người dùng — tab "Xin OT"): cho
                // phép xin TRƯỚC khi còn ĐANG trong ca, không chỉ SAU khi đã
                // chấm công ra như trước — lúc đó overtime_minutes vẫn đang
                // là 0 (chưa tính được), không thể bắt phải >0 nữa. Vẫn phải
                // ĐÃ chấm công vào (mới có gì để "đang làm thêm giờ").
                if (! $attendance->first_check_in_at) {
                    throw ValidationException::withMessages([
                        'attendance_id' => 'Bạn chưa chấm công vào ca này, chưa thể xin duyệt OT.',
                    ]);
                }

                // ĐÃ chấm công RA mà không có phút OT nào thì mới chắc chắn
                // không có gì để duyệt — còn ĐANG trong ca thì chưa biết
                // trước, số phút OT thật sẽ tính đúng lúc chấm công ra (xem
                // decide(): duyệt chỉ set cờ overtime_approved=true NGAY,
                // không đụng số phút).
                if ($attendance->last_check_out_at && $attendance->overtime_minutes <= 0) {
                    throw ValidationException::withMessages([
                        'attendance_id' => 'Bản ghi này không có giờ làm thêm, không thể xin duyệt OT.',
                    ]);
                }

                if ($attendance->overtime_approved) {
                    throw ValidationException::withMessages([
                        'attendance_id' => 'Bản ghi này đã được duyệt OT trước đó.',
                    ]);
                }
            }

            $data['work_shift_id'] = $attendance->work_shift_id;
            $data['attendance_date'] = $attendance->attendance_date;
        }

        $data['requested_by'] = $requestedBy;
        $data['status'] = 'pending';

        $adjustment = $this->attendanceAdjustmentRepository->create($data);

        $this->notifyApprovers($employee, $adjustment, $requestedBy);

        return $adjustment;
    }

    // Đơn mới nộp -> báo mọi người có quyền duyệt (attendance.adjust, cùng
    // quyền gate route PUT /attendances/adjustments/{id}). Bỏ qua chính người
    // nộp — HR/Admin tự nộp đơn của mình thì không cần tự báo cho mình.
    private function notifyApprovers(Employee $employee, AttendanceAdjustment $adjustment, int $requestedBy): void
    {
        $label = self::TYPE_LABELS[$adjustment->type] ?? 'điều chỉnh công';
        $date = Carbon::parse($adjustment->attendance_date)->format('d/m/Y');
        $dateText = $adjustment->attendance_date_to
            ? 'từ ngày '.$date.' đến '.Carbon::parse($adjustment->attendance_date_to)->format('d/m/Y')
            : 'ngày '.$date;
        $title = 'Đơn điều chỉnh công mới cần duyệt';
        $message = "{$employee->full_name} vừa gửi đơn xin {$label} {$dateText}.";
        $data = ['attendance_adjustment_id' => $adjustment->id];

        $approvers = User::withPermission('attendance.adjust')->where('id', '!=', $requestedBy)->get();

        foreach ($approvers as $approver) {
            $this->notificationService->send($approver, 'attendance_adjustment.pending', $title, $message, $data);
        }
    }

    // 'extra_shift' (2026-09-23): ĐĂNG KÝ TRƯỚC cho 1 ngày/ca KHÔNG có
    // trong lịch gán — chỉ hợp lệ khi (1) Ca còn hoạt động, (2) ngày đăng ký
    // là HÔM NAY hoặc TƯƠNG LAI (quá khứ thì dùng "Bổ sung chấm công" —
    // supplement — nếu đã lỡ làm rồi), (3) CHƯA có ca này vào đúng ngày đó
    // (đã có thì cứ chấm công bình thường, không cần đăng ký), (4) chưa có
    // yêu cầu extra_shift nào khác đang chờ duyệt cho đúng ca+ngày này
    // (tránh duyệt trùng tạo 2 bản gán ca).
    private function assertExtraShiftRequestIsValid(Employee $employee, array $data): void
    {
        $workShift = WorkShift::find($data['work_shift_id'] ?? null);

        if (! $workShift || ! $workShift->is_active) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'Ca làm việc không tồn tại hoặc đã ngừng hoạt động.',
            ]);
        }

        $from = Carbon::parse($data['attendance_date']);
        $to = ! empty($data['attendance_date_to']) ? Carbon::parse($data['attendance_date_to']) : $from->copy();

        if ($from->lt(Carbon::today())) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Chỉ đăng ký được cho hôm nay hoặc ngày trong tương lai — đã lỡ làm rồi thì dùng "Xin bổ sung chấm công".',
            ]);
        }

        if ($from->diffInDays($to) + 1 > self::EXTRA_SHIFT_MAX_DAYS) {
            throw ValidationException::withMessages([
                'attendance_date_to' => 'Mỗi đơn chỉ đăng ký tối đa '.self::EXTRA_SHIFT_MAX_DAYS.' ngày liên tiếp.',
            ]);
        }

        // Ngày nào đã có ca này trong lịch thì không cần đăng ký (bỏ qua, không
        // lỗi) — nhưng nếu CẢ khoảng đều đã có ca thì đơn vô nghĩa.
        if ($this->extraShiftDatesNeedingUnlock($employee, $workShift, $from, $to) === []) {
            throw ValidationException::withMessages([
                'work_shift_id' => $from->equalTo($to)
                    ? 'Bạn đã có ca này trong lịch vào ngày đã chọn, không cần đăng ký thêm.'
                    : 'Bạn đã có ca này trong lịch ở mọi ngày đã chọn, không cần đăng ký thêm.',
            ]);
        }

        // Đơn chờ duyệt cùng ca mà khoảng ngày chồng lên khoảng này.
        $alreadyRequested = AttendanceAdjustment::where('employee_id', $employee->id)
            ->where('work_shift_id', $workShift->id)
            ->where('type', 'extra_shift')
            ->where('status', 'pending')
            ->where('attendance_date', '<=', $to->toDateString())
            ->whereRaw('COALESCE(attendance_date_to, attendance_date) >= ?', [$from->toDateString()])
            ->exists();

        if ($alreadyRequested) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'Bạn đã gửi yêu cầu làm ca này trong khoảng ngày đã chọn, đang chờ duyệt.',
            ]);
        }
    }

    // 'overtime_shift' — OT ngày khác: chỉ cho ngày nhân viên KHÔNG có ca làm (T7/CN,
    // ngày ngoài lịch) hoặc ngày lễ. Ngày có ca thì làm thêm sau ca dùng "Xin OT".
    // Mỗi ngày tối đa 1 đơn OT đang chờ duyệt/đã duyệt.
    private function assertOvertimeShiftRequestIsValid(Employee $employee, Carbon $date): void
    {
        $isHoliday = Holiday::whereDate('holiday_date', $date->toDateString())->exists();
        $hasRegularShift = $this->attendanceService->listActiveAssignmentsForDate($employee, $date)
            ->contains(fn ($assignment) => ! $assignment->workShift->is_overtime);

        if ($hasRegularShift && ! $isHoliday) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Ngày này bạn có ca làm việc — làm thêm sau giờ tan ca thì dùng "Xin OT" cho ca đó.',
            ]);
        }

        $alreadyRequested = AttendanceAdjustment::where('employee_id', $employee->id)
            ->where('type', 'overtime_shift')
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('attendance_date', $date->toDateString())
            ->exists();

        if ($alreadyRequested) {
            throw ValidationException::withMessages([
                'attendance_date' => 'Bạn đã có đơn OT cho ngày này (đang chờ duyệt hoặc đã duyệt).',
            ]);
        }
    }

    // Các ngày trong [from, to] mà nhân viên CHƯA có ca này trong lịch — chính là
    // những ngày cần "mở khóa" bằng bản gán 1 ngày khi đơn được duyệt.
    /** @return array<int, Carbon> */
    private function extraShiftDatesNeedingUnlock(Employee $employee, WorkShift $workShift, Carbon $from, Carbon $to): array
    {
        $dates = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (! $this->attendanceService->resolveActiveAssignment($employee, $workShift, $day)) {
                $dates[] = $day->copy();
            }
        }

        return $dates;
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->attendanceAdjustmentRepository->listForEmployee($employee);
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->attendanceAdjustmentRepository->paginate(perPage: $perPage, filters: $filters);
    }

    // status: 'approved' | 'rejected'. Duyệt 'correction'/'supplement' thì áp
    // dụng giờ đề xuất vào Attendance gốc (AttendanceService::
    // applyAdjustment(), tái dùng công thức tính trễ/sớm/giờ công); duyệt
    // 'excuse'/'overtime' thì CHỈ set cờ late_excused/overtime_approved=true,
    // KHÔNG đụng tới giờ hay gọi applyAdjustment() vì không có mốc giờ nào
    // được đề xuất — từ chối thì ở mọi loại chỉ ghi nhận quyết định, không
    // đụng gì tới Attendance.
    public function decide(AttendanceAdjustment $adjustment, string $status, ?string $decisionNote, int $approvedBy): AttendanceAdjustment
    {
        if ($adjustment->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Yêu cầu này đã được xử lý trước đó, không thể duyệt lại.',
            ]);
        }

        $adjustment = DB::transaction(function () use ($adjustment, $status, $decisionNote, $approvedBy) {
            $adjustment->forceFill([
                'status' => $status,
                'decision_note' => $decisionNote,
                'approved_by' => $approvedBy,
                'decided_at' => now(),
            ])->save();

            if ($status === 'approved') {
                if ($adjustment->type === 'excuse') {
                    $adjustment->attendance->forceFill(['late_excused' => true])->save();

                    return $adjustment;
                }

                if ($adjustment->type === 'overtime') {
                    $adjustment->attendance->forceFill(['overtime_approved' => true])->save();

                    return $adjustment;
                }

                // Duyệt 'extra_shift' KHÔNG đụng gì tới Attendance (chưa có
                // gì để chỉnh — nhân viên chưa làm) — chỉ MỞ KHÓA cho họ tự
                // chấm công vào đúng ngày đã đăng ký, bằng cách tạo 1 bản
                // gán ca CHỈ ÁP DỤNG ĐÚNG 1 NGÀY đó (effective_from=
                // effective_to=ngày đăng ký). Từ lúc này, luồng Chấm công
                // (AttendanceService::checkIn()/checkOut()) chạy HỆT như 1
                // ca bình thường, không cần sửa gì thêm ở đó.
                // OT ngày khác: tạo ca OT đúng khung giờ đã đăng ký + gán đúng ngày đó.
                // Nhân viên chấm công bình thường; mọi phút trong khung là OT đã duyệt.
                if ($adjustment->type === 'overtime_shift') {
                    $shift = $this->workShiftService->createOvertimeOneOff(
                        $adjustment->proposed_check_in_at->format('H:i'),
                        $adjustment->proposed_check_out_at->format('H:i'),
                    );
                    $this->employeeShiftAssignmentService->createOneOffAssignment(
                        $adjustment->employee,
                        $shift,
                        Carbon::parse($adjustment->attendance_date),
                    );
                    $adjustment->forceFill(['work_shift_id' => $shift->id])->save();

                    return $adjustment;
                }

                if ($adjustment->type === 'extra_shift') {
                    // Đơn có thể phủ NHIỀU ngày liền nhau (attendance_date_to): mỗi
                    // ngày chưa có ca này trong lịch được mở khóa bằng 1 bản gán 1 ngày.
                    $from = Carbon::parse($adjustment->attendance_date);
                    $to = $adjustment->attendance_date_to ? Carbon::parse($adjustment->attendance_date_to) : $from->copy();

                    foreach ($this->extraShiftDatesNeedingUnlock($adjustment->employee, $adjustment->workShift, $from, $to) as $date) {
                        $this->employeeShiftAssignmentService->createOneOffAssignment(
                            $adjustment->employee,
                            $adjustment->workShift,
                            $date,
                        );
                    }

                    return $adjustment;
                }

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

                // HR duyệt yêu cầu điều chỉnh/bổ sung CHÍNH LÀ đã xem xét và
                // chấp nhận giờ vào/ra của bản ghi này — duyệt luôn để không
                // bắt HR duyệt lần hai ở màn "Duyệt chấm công" (bản ghi 'bổ
                // sung' mới tạo còn mặc định 'pending' nên sẽ mãi không có
                // công nếu không làm bước này). 'excuse'/'overtime' ở trên
                // không đụng giờ nên không đi qua đây.
                $this->attendanceService->markFullyApproved(
                    $attendance,
                    $approvedBy,
                    "Duyệt cùng yêu cầu điều chỉnh công #{$adjustment->id}.",
                );
            }

            return $adjustment;
        });


        return $adjustment;
    }
}
