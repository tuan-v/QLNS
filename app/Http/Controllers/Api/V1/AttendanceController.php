<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\CheckInRequest;
use App\Http\Requests\Attendance\CheckOutRequest;
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
        $filters = $request->only(['employee_id', 'date_from', 'date_to', 'status']);
        $perPage = (int) $request->input('per_page', 15);

        return response()->json($this->attendanceService->list($filters, $perPage));
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
