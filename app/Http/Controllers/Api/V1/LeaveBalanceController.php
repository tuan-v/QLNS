<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\LeaveBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Quản lý số dư phép (2026-10-15) — xem LeaveBalanceService.
class LeaveBalanceController extends Controller
{
    public function __construct(private readonly LeaveBalanceService $leaveBalanceService)
    {
    }

    // Chi tiết số dư + lịch sử của 1 nhân viên (HR: leave.view_all).
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);

        return response()->json(['data' => $this->leaveBalanceService->detail($employee, $year)]);
    }

    // Của chính mình — nhân viên xem vì sao số dư đổi.
    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return response()->json(['data' => $this->leaveBalanceService->detail($employee, (int) $request->input('year', now()->year))]);
    }

    // HR cộng/trừ tay (leave.balance_adjust) — bắt buộc lý do.
    public function adjust(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where(fn ($q) => $q->where('annual_entitlement_days', '>', 0))],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'days' => ['required', 'numeric', 'not_in:0', 'between:-365,365', 'multiple_of:0.5'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'leave_type_id.exists' => 'Chỉ điều chỉnh được loại phép có quỹ theo năm',
            'days.required' => 'Vui lòng nhập số ngày',
            'days.not_in' => 'Số ngày điều chỉnh phải khác 0',
            'days.multiple_of' => 'Số ngày phải là bội số của 0,5',
            'days.between' => 'Số ngày điều chỉnh không hợp lệ',
            'reason.required' => 'Vui lòng ghi lý do điều chỉnh',
            'reason.min' => 'Lý do quá ngắn (tối thiểu 5 ký tự)',
        ]);

        $this->leaveBalanceService->adjust(
            $request->user(),
            $employee,
            LeaveType::findOrFail($data['leave_type_id']),
            (int) $data['year'],
            (float) $data['days'],
            $data['reason'],
        );

        return response()->json([
            'message' => 'Đã điều chỉnh số dư phép.',
            'data' => $this->leaveBalanceService->detail($employee, (int) $data['year']),
        ]);
    }

    // Chính sách chuyển phép của 1 loại phép — chỉ Admin (leave.manage).
    public function updatePolicy(Request $request, LeaveType $leaveType): JsonResponse
    {
        $data = $request->validate([
            'allow_carry_forward' => ['required', 'boolean'],
            'max_carry_forward_days' => ['required_if:allow_carry_forward,true', 'nullable', 'numeric', 'between:0.5,30'],
            'carry_forward_expiry' => ['required_if:allow_carry_forward,true', 'nullable', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
        ], [
            'max_carry_forward_days.required_if' => 'Vui lòng nhập số ngày tối đa được chuyển',
            'max_carry_forward_days.between' => 'Số ngày chuyển tối đa từ 0,5 đến 30',
            'carry_forward_expiry.required_if' => 'Vui lòng chọn hạn dùng phép chuyển',
            'carry_forward_expiry.regex' => 'Hạn dùng không hợp lệ',
        ]);

        abort_if((float) $leaveType->annual_entitlement_days <= 0, 422, 'Loại phép này không có quỹ theo năm.');

        $leaveType->update([
            'allow_carry_forward' => $data['allow_carry_forward'],
            'max_carry_forward_days' => $data['allow_carry_forward'] ? $data['max_carry_forward_days'] : null,
            'carry_forward_expiry' => $data['allow_carry_forward'] ? $data['carry_forward_expiry'] : null,
        ]);

        return response()->json(['message' => 'Đã cập nhật chính sách chuyển phép.', 'data' => $leaveType->fresh()]);
    }
}
