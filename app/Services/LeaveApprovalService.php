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

        // Thông báo/mail SAU KHI transaction đã commit xong — tránh trường
        // hợp transaction rollback (lỗi phát sinh) mà đã báo tin không đúng
        // sự thật. 2 nhánh: vừa qua cấp 1 (còn chờ HR) báo HR biết tới lượt
        // mình; xong CUỐI (approved/rejected) báo nhân viên.
        if ($decidedLeaveRequest->status === 'manager_approved') {
            $this->notifyHrTurn($decidedLeaveRequest, $decidingEmployee);
        } elseif (in_array($decidedLeaveRequest->status, ['approved', 'rejected'], true)) {
            $this->notifyEmployeeDecision($decidedLeaveRequest);
        }

        // Báo TẤT CẢ Manager/HR đang mở trang "Duyệt nghỉ phép" tự làm mới
        // danh sách — khác 2 nhánh thông báo cá nhân ở trên (chỉ tới ĐÚNG
        // người ở đúng cấp), sự kiện này tới CẢ những người không phải người
        // nhận thông báo lần này (vd HR B khi HR A vừa duyệt xong cấp cuối).
        LeaveRequestChanged::dispatch($decidedLeaveRequest);

        return $decidedLeaveRequest;
    }

    // Duyệt/Từ chối HÀNG LOẠT (2026-09-25, theo yêu cầu người dùng, kèm ảnh
    // tham khảo) — lặp decide() cho TỪNG đơn, KHÔNG viết lại luật 2 cấp riêng
    // ở đây. Không dừng cả loạt nếu 1 đơn lỗi (vd đơn đó đã ở cấp khác với
    // người đang bấm, hoặc đã được người khác xử lý xong ngay trước đó) — trả
    // đủ "succeeded"/"failed" để Frontend báo rõ.
    public function bulkDecide(array $leaveRequestIds, Employee $decidingEmployee, bool $isHr, string $decision, ?string $comment): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($leaveRequestIds as $leaveRequestId) {
            $leaveRequest = LeaveRequest::find($leaveRequestId);

            if ($leaveRequest === null) {
                $failed[] = ['id' => $leaveRequestId, 'message' => 'Đơn không tồn tại.'];

                continue;
            }

            try {
                $this->decide($leaveRequest, $decidingEmployee, $isHr, $decision, $comment);
                $succeeded[] = $leaveRequestId;
            } catch (ValidationException $e) {
                $failed[] = ['id' => $leaveRequestId, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    // Quản lý vừa duyệt (cấp 1) -> tới lượt HR (cấp 2). Loại trừ chính người
    // vừa duyệt khỏi danh sách nhận báo (tránh tự thông báo cho mình trong
    // trường hợp họ vừa là Manager vừa có quyền leave.approve_hr).
    private function notifyHrTurn(LeaveRequest $leaveRequest, Employee $decidingEmployee): void
    {
        $leaveRequest->loadMissing(['employee', 'leaveType']);
        $title = 'Đơn nghỉ phép chờ HR duyệt';
        $message = "Đơn xin {$leaveRequest->leaveType->name} của {$leaveRequest->employee->full_name} đã qua duyệt cấp quản lý, đang chờ HR.";
        $data = ['leave_request_id' => $leaveRequest->id];

        foreach (User::withPermission('leave.approve_hr')->get() as $hrUser) {
            if ($hrUser->id === $decidingEmployee->user?->id) {
                continue;
            }
            $this->notificationService->send($hrUser, 'leave.pending_hr', $title, $message, $data);
        }
    }

    // Có kết quả CUỐI (approved/rejected) -> báo nhân viên qua web (2026-09-24,
    // theo yêu cầu người dùng: bỏ hẳn email, chỉ còn thông báo trong-app —
    // trước đó gửi kèm LeaveDecisionMail, đã xóa). Nhân viên luôn có tài
    // khoản đăng nhập tới đây (StoreLeaveRequest chỉ nhận đơn từ
    // $request->user()->employee, xem LeaveRequestController::store()), nên
    // không cần nhánh dự phòng "chưa có tài khoản" như code cũ.
    private function notifyEmployeeDecision(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing(['employee', 'leaveType']);
        $employee = $leaveRequest->employee;

        $title = $leaveRequest->status === 'approved'
            ? 'Đơn xin nghỉ phép của bạn đã được duyệt'
            : 'Đơn xin nghỉ phép của bạn đã bị từ chối';
        $message = "Đơn xin {$leaveRequest->leaveType->name} ({$leaveRequest->total_days} ngày) của bạn đã có kết quả.";

        $this->notificationService->send(
            $employee->user,
            'leave.decided',
            $title,
            $message,
            ['leave_request_id' => $leaveRequest->id],
        );
    }
}
