<?php

namespace App\Services;

use App\Mail\LeaveDecisionMail;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Repositories\LeaveApprovalRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class LeaveApprovalService
{
    public function __construct(
        private readonly LeaveApprovalRepository $leaveApprovalRepository,
        private readonly LeaveRequestService $leaveRequestService,
    ) {
    }

    // Luồng nhiều cấp: pending -> (cấp 1: Manager) -> manager_approved -> (cấp
    // 2: HR) -> approved. Từ chối ở BẤT KỲ cấp nào cũng kết thúc luôn
    // (status='rejected'), Manager được từ chối trực tiếp, không cần chuyển
    // lên HR (xác nhận với người dùng khi làm Ngày 37). Nhân viên KHÔNG có
    // quản lý trực tiếp (manager_id null) thì bỏ qua cấp 1, HR duyệt thẳng.
    public function decide(LeaveRequest $leaveRequest, Employee $decidingEmployee, bool $isHr, string $decision, ?string $comment): LeaveRequest
    {
        if (in_array($leaveRequest->status, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Đơn này đã được xử lý xong.',
            ]);
        }

        if ($leaveRequest->status === 'pending' && $leaveRequest->employee->manager_id !== null) {
            $approvalLevel = 1;

            if ($decidingEmployee->id !== $leaveRequest->employee->manager_id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'Bạn không phải quản lý trực tiếp của nhân viên này.',
                ]);
            }
        } else {
            // Cấp 2 (HR) — hoặc vì đã qua Manager duyệt (status='manager_approved'),
            // hoặc vì nhân viên không có quản lý trực tiếp nên bỏ qua cấp 1.
            $approvalLevel = 2;

            if (! $isHr) {
                throw ValidationException::withMessages([
                    'status' => 'Chỉ HR mới được duyệt đơn ở bước này.',
                ]);
            }
        }

        $decidedLeaveRequest = DB::transaction(function () use ($leaveRequest, $decidingEmployee, $approvalLevel, $decision, $comment) {
            $this->leaveApprovalRepository->create([
                'leave_request_id' => $leaveRequest->id,
                'approver_employee_id' => $decidingEmployee->id,
                'approval_level' => $approvalLevel,
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            if ($decision === 'rejected') {
                $nextStatus = 'rejected';
            } elseif ($approvalLevel === 1) {
                $nextStatus = 'manager_approved';
            } else {
                $nextStatus = 'approved';
            }

            $leaveRequest->forceFill(['status' => $nextStatus])->save();

            if ($nextStatus === 'approved') {
                $this->leaveRequestService->deductBalance($leaveRequest);
            }

            return $leaveRequest;
        });

        // Gửi mail SAU KHI transaction đã commit xong — tránh trường hợp
        // transaction rollback (lỗi phát sinh) mà mail đã queue báo tin
        // không đúng sự thật. Chỉ gửi khi status CUỐI là approved/rejected
        // (2 trạng thái terminal) — không gửi ở manager_approved (còn chờ HR).
        if (in_array($decidedLeaveRequest->status, ['approved', 'rejected'], true)) {
            $decidedLeaveRequest->loadMissing(['employee', 'leaveType']);
            Mail::to($decidedLeaveRequest->employee->company_email)
                ->send(new LeaveDecisionMail($decidedLeaveRequest, $comment));
        }

        return $decidedLeaveRequest;
    }
}
