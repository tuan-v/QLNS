<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\CheckInRequest;
use App\Http\Requests\Attendance\CheckOutRequest;
use App\Http\Requests\Attendance\DecideAttendanceApprovalRequest;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceService)
    {
    }

    // Chấm công luôn là chấm cho CHÍNH người gọi API — không nhận employee_id
    // từ client, tránh 1 tài khoản chấm công hộ người khác.
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        $log = $this->attendanceService->checkIn($employee, $request->validated());
        $log->load(['attendance.workShift', 'attendanceLocation']);

        return response()->json($log, 201);
    }

    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        $log = $this->attendanceService->checkOut($employee, $request->validated());
        $log->load(['attendance.workShift', 'attendanceLocation']);

        return response()->json($log, 201);
    }

    // Trạng thái chấm công hôm nay theo TỪNG CA đang gán (mục 14, có thể
    // nhiều ca/ngày) — mảng [{work_shift, attendance}], không còn 1 bản ghi
    // duy nhất cho cả ngày (xem AttendanceService::listTodayStatusForEmployee()).
    public function today(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return response()->json(['data' => $this->attendanceService->listTodayStatusForEmployee($employee)]);
    }

    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return response()->json($this->attendanceService->listForEmployee($employee));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['employee_id', 'date_from', 'date_to', 'status', 'approval_status']);
        $perPage = (int) $request->input('per_page', 15);

        return response()->json($this->attendanceService->list($filters, $perPage));
    }

    // HR duyệt / từ chối 1 bản ghi chấm công (quyền attendance.approve, xem
    // route) — chưa duyệt thì không tính công/lương. Tên field khớp
    // DecideAttendanceAdjustmentRequest (status + decision_note).
    public function decideApproval(DecideAttendanceApprovalRequest $request, Attendance $attendance): JsonResponse
    {
        $attendance = $this->attendanceService->decideApproval(
            $attendance,
            $request->validated('status'),
            $request->validated('decision_note'),
            $request->user()->id,
        );
        $attendance->load(['employee', 'workShift', 'logs.attendanceLocation', 'approvedBy']);

        return response()->json($attendance);
    }

    // "Tổng hợp chấm công trong ngày" (2026-09-23, theo yêu cầu người dùng) —
    // màn cho HR/Manager xem TOÀN CÔNG TY vào 1 ngày, kể cả ai chưa chấm công
    // (mục 16, quyền attendance.view_all). Mặc định hôm nay, không validate
    // chặt định dạng ngày — cùng mức tin dữ liệu client như buildHistory() ở
    // dưới (input luôn do InputDate.vue trên FE gửi lên, không phải form tự
    // do cho người dùng gõ tay).
    public function overview(Request $request): JsonResponse
    {
        $date = $request->input('date') ?: now()->toDateString();
        $departmentId = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        $workShiftId = $request->filled('work_shift_id') ? (int) $request->input('work_shift_id') : null;
        $status = $request->input('status') ?: null;
        $approvalStatus = $request->input('approval_status') ?: null;

        return response()->json([
            'data' => $this->attendanceService->dailyOverview($date, $departmentId, $workShiftId, $status, $approvalStatus),
        ]);
    }

    // Báo cáo "Lịch sử chấm công" (mục 18) CHÍNH MÌNH — chỉ hiển thị, không
    // sửa được gì. Cùng khuôn "chính mình" như mine()/today() ở trên.
    public function historyMine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return response()->json(['data' => $this->buildHistory($employee, $request)]);
    }

    // Cùng báo cáo nhưng cho 1 nhân viên BẤT KỲ — phía Admin/quản lý, quyền
    // attendance.view_all (xem route).
    public function history(Request $request, Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->buildHistory($employee, $request)]);
    }

    private function buildHistory(Employee $employee, Request $request): array
    {
        $dateFrom = $request->input('date_from') ?: now()->startOfMonth()->toDateString();
        $dateTo = $request->input('date_to') ?: now()->endOfMonth()->toDateString();
        $workShiftId = $request->filled('work_shift_id') ? (int) $request->input('work_shift_id') : null;
        $status = $request->input('status') ?: null;

        return $this->attendanceService->history($employee, $dateFrom, $dateTo, $workShiftId, $status);
    }
}
