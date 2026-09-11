<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\DecideLeaveRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Models\LeaveRequest;
use App\Services\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\LeaveApprovalService;

class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequestService,
        private readonly LeaveApprovalService $leaveApprovalService
    ) {
    }

    // Luôn tạo đơn cho CHÍNH người gọi API — không nhận employee_id từ
    // client, giống toàn bộ nhóm "chính mình" khác trong dự án (chấm công...).
    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        $leaveRequest = $this->leaveRequestService->create($employee, $request->validated());
        $leaveRequest->load('leaveType');

        return response()->json($leaveRequest, 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return response()->json($this->leaveRequestService->listForEmployee($employee));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['employee_id', 'leave_type_id', 'status']);
        $perPage = (int) $request->input('per_page', 15);

        return response()->json($this->leaveRequestService->list($filters, $perPage));
    }
    public function decide(DecideLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $decidingEmployee = $request->user()->employee;
        abort_if(! $decidingEmployee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        $isHr = $request->user()->hasPermission('leave.approve_hr');
        $leaveRequest = $this->leaveApprovalService->decide(
            $leaveRequest,
            $decidingEmployee,
            $isHr,
            $request->validated('status'),
            $request->validated('comment'),
        );
        $leaveRequest->load('leaveType', 'employee', 'approvals.approverEmployee');

        return response()->json($leaveRequest);
    }
}
