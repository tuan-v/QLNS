<?php

namespace App\Services;

use App\Events\LeaveRequestChanged;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Repositories\LeaveApprovalRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveApprovalService
{
    public function __construct(
        private readonly LeaveApprovalRepository $leaveApprovalRepository,
        private readonly LeaveRequestService $leaveRequestService,
        private readonly NotificationService $notificationService,
    ) {
    }

    // Luồng nhiều cấp: pending -> (cấp 1: Manager hoặc HR) -> manager_approved -> (cấp
    // 2: HR) -> approved. Từ chối ở BẤT KỲ cấp nào cũng kết thúc luôn
    // (status='rejected'), Manager hoặc HR được từ chối trực tiếp.
    // Nếu người duyệt có quyền (leave.approve_hr hoặc leave.approve_manager)
    // nhưng tài khoản User không gắn hồ sơ Employee, vẫn duyệt/từ chối bình thường.
    public function decide(
        LeaveRequest $leaveRequest,
        User|Employee $decidingActor,
        bool $isHr,
        string $decision,
        ?string $comment = null
    ): LeaveRequest {
        $decidingUser = $decidingActor instanceof User ? $decidingActor : $decidingActor->user;
        $decidingEmployee = $decidingActor instanceof Employee ? $decidingActor : $decidingActor->employee;

        if (in_array($leaveRequest->status, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Đơn này đã được xử lý xong.',
            ]);
        }

        if ($leaveRequest->status === 'pending' && $leaveRequest->employee->manager_id !== null) {
            $approvalLevel = 1;

            $isDirectManager = $decidingEmployee && $decidingEmployee->id === $leaveRequest->employee->manager_id;
            if (! $isDirectManager && ! $isHr) {
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

        $decidedLeaveRequest = DB::transaction(function () use ($leaveRequest, $decidingEmployee, $decidingUser, $approvalLevel, $decision, $comment) {
            $this->leaveApprovalRepository->create([
                'leave_request_id' => $leaveRequest->id,
                'approver_employee_id' => $decidingEmployee?->id,
                'approver_user_id' => $decidingUser?->id,
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

        // Thông báo SAU KHI transaction đã commit xong
        if ($decidedLeaveRequest->status === 'manager_approved') {
            $this->notifyHrTurn($decidedLeaveRequest, $decidingUser);
        } elseif (in_array($decidedLeaveRequest->status, ['approved', 'rejected'], true)) {
            $this->notifyEmployeeDecision($decidedLeaveRequest, $comment);
        }

        LeaveRequestChanged::dispatch($decidedLeaveRequest);

        return $decidedLeaveRequest;
    }

    // Duyệt/Từ chối HÀNG LOẠT (2026-09-25, theo yêu cầu người dùng, kèm ảnh
    // tham khảo) — lặp decide() cho TỪNG đơn, KHÔNG viết lại luật 2 cấp riêng
    // ở đây.
    public function bulkDecide(
        array $leaveRequestIds,
        User|Employee $decidingActor,
        bool $isHr,
        string $decision,
        ?string $comment = null
    ): array {
        $succeeded = [];
        $failed = [];

        foreach ($leaveRequestIds as $leaveRequestId) {
            $leaveRequest = LeaveRequest::find($leaveRequestId);

            if ($leaveRequest === null) {
                $failed[] = ['id' => $leaveRequestId, 'message' => 'Đơn không tồn tại.'];

                continue;
            }

            try {
                $this->decide($leaveRequest, $decidingActor, $isHr, $decision, $comment);
                $succeeded[] = $leaveRequestId;
            } catch (ValidationException $e) {
                $failed[] = ['id' => $leaveRequestId, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    // Quản lý vừa duyệt (cấp 1) -> tới lượt HR (cấp 2). Loại trừ chính người
    // vừa duyệt khỏi danh sách nhận báo (tránh tự thông báo cho mình).
    private function notifyHrTurn(LeaveRequest $leaveRequest, ?User $decidingUser): void
    {
        $leaveRequest->loadMissing(['employee', 'leaveType']);
        $title = 'Đơn nghỉ phép chờ HR duyệt';
        $message = "Đơn xin {$leaveRequest->leaveType->name} của {$leaveRequest->employee->full_name} đã qua duyệt cấp quản lý, đang chờ HR.";
        $data = ['leave_request_id' => $leaveRequest->id];

        foreach (User::withPermission('leave.approve_hr')->get() as $hrUser) {
            if ($decidingUser && $hrUser->id === $decidingUser->id) {
                continue;
            }
            $this->notificationService->send($hrUser, 'leave.pending_hr', $title, $message, $data);
        }
    }

    // Có kết quả CUỐI (approved/rejected) -> báo người nộp đơn qua thông báo trong-app kèm lý do
    private function notifyEmployeeDecision(LeaveRequest $leaveRequest, ?string $comment = null): void
    {
        $leaveRequest->loadMissing(['employee.user', 'leaveType']);
        $employee = $leaveRequest->employee;

        if (! $employee || ! $employee->user) {
            return;
        }

        $isApproved = $leaveRequest->status === 'approved';
        $title = $isApproved
            ? 'Đơn xin nghỉ phép của bạn đã được duyệt'
            : 'Đơn xin nghỉ phép của bạn đã bị từ chối';

        $commentSnippet = $comment !== null && trim($comment) !== '' ? trim($comment) : null;
        if ($isApproved) {
            $message = "Đơn xin {$leaveRequest->leaveType->name} ({$leaveRequest->total_days} ngày) của bạn đã được duyệt."
                . ($commentSnippet ? " Ghi chú: {$commentSnippet}" : '');
        } else {
            $message = "Đơn xin {$leaveRequest->leaveType->name} ({$leaveRequest->total_days} ngày) của bạn đã bị từ chối."
                . ($commentSnippet ? " Lý do: {$commentSnippet}" : '');
        }

        $this->notificationService->send(
            $employee->user,
            'leave.decided',
            $title,
            $message,
            [
                'leave_request_id' => $leaveRequest->id,
                'status' => $leaveRequest->status,
                'comment' => $commentSnippet,
            ],
        );
    }
}
