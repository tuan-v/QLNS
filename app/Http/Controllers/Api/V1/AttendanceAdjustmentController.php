<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\DecideAttendanceAdjustmentRequest;
use App\Http\Requests\Attendance\StoreAttendanceAdjustmentRequest;
use App\Models\AttendanceAdjustment;
use App\Services\AttendanceAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceAdjustmentController extends Controller
{
    public function __construct(private readonly AttendanceAdjustmentService $attendanceAdjustmentService)
    {
    }

    // Xin điều chỉnh công CHO CHÍNH MÌNH — không nhận employee_id từ client,
    // luôn resolve theo token đăng nhập (cùng khuôn với checkIn()/checkOut()).
    public function store(StoreAttendanceAdjustmentRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        $adjustment = $this->attendanceAdjustmentService->requestForEmployee(
            $employee,
            $request->validated(),
            $request->user()->id,
        );
        $adjustment->load(['employee', 'workShift', 'attendance.workShift']);

        return response()->json($adjustment, 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return response()->json($this->attendanceAdjustmentService->listForEmployee($employee));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status']);
        $perPage = (int) $request->input('per_page', 15);

        return response()->json($this->attendanceAdjustmentService->list($filters, $perPage));
    }

    public function decide(DecideAttendanceAdjustmentRequest $request, AttendanceAdjustment $adjustment): JsonResponse
    {
        $adjustment = $this->attendanceAdjustmentService->decide(
            $adjustment,
            $request->validated('status'),
            $request->validated('decision_note'),
            $request->user()->id,
        );
        $adjustment->load(['employee', 'workShift', 'attendance.workShift', 'approvedBy']);

        return response()->json($adjustment);
    }
}
