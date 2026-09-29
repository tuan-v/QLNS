<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\ResignationRequest;
use App\Models\User;
use App\Repositories\NotificationRepository;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function __construct(private readonly NotificationRepository $notificationRepository)
    {
    }

    public function listForUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $notifications = $this->notificationRepository->paginateForUser($user, $perPage);
        $this->attachActors($notifications->getCollection());

        return $notifications;
    }

    public function unreadCountForUser(User $user): int
    {
        return $this->notificationRepository->unreadCountForUser($user);
    }

    public function listAll(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $notifications = $this->notificationRepository->paginateAll($filters, $perPage);
        $this->attachActors($notifications->getCollection());

        return $notifications;
    }

    // Loại thông báo "có người gây ra" -> [khóa id trong `data`, Model gốc
    // có quan hệ employee]. Loại không có ở đây (leave.decided,
    // attendance.checkout_reminder...) là thông báo HỆ THỐNG, không có actor.
    private const ACTOR_SOURCES = [
        'leave.pending_manager' => ['leave_request_id', LeaveRequest::class],
        'leave.pending_hr' => ['leave_request_id', LeaveRequest::class],
        'attendance.pending_approval' => ['attendance_id', Attendance::class],
        'attendance_adjustment.pending' => ['attendance_adjustment_id', AttendanceAdjustment::class],
        'resignation.pending' => ['resignation_request_id', ResignationRequest::class],
    ];

    // Gắn quan hệ ảo `actor` (Employee đã gây ra thông báo — để chuông hiện
    // ảnh/tên/chấm online người gửi). SUY RA từ bản ghi gốc trong `data`
    // thay vì lưu thêm cột, nên áp dụng được luôn cho thông báo CŨ. Nạp theo
    // lô (1 query/loại Model), không N+1 theo từng thông báo.
    private function attachActors(Collection $notifications): void
    {
        foreach ($notifications->groupBy(fn (Notification $n) => self::ACTOR_SOURCES[$n->type][1] ?? '') as $modelClass => $group) {
            if ($modelClass === '') {
                $group->each(fn (Notification $n) => $n->setRelation('actor', null));

                continue;
            }

            $ids = $group->map(fn (Notification $n) => $n->data[self::ACTOR_SOURCES[$n->type][0]] ?? null)->filter()->unique();
            $employeesBySourceId = $modelClass::with('employee')->whereIn('id', $ids)->get()
                ->mapWithKeys(fn ($record) => [$record->id => $record->employee]);

            $group->each(function (Notification $n) use ($employeesBySourceId) {
                $sourceId = $n->data[self::ACTOR_SOURCES[$n->type][0]] ?? null;
                $n->setRelation('actor', $employeesBySourceId[$sourceId] ?? null);
            });
        }
    }

    public function markRead(User $user, string $id): Notification
    {
        // Chỉ tìm trong đúng thông báo của $user (xem findForUser) — không
        // tiết lộ có tồn tại thông báo của người khác hay không, trả 404
        // giống mọi chỗ khác trong dự án khi bản ghi không thuộc về mình.
        $notification = $this->notificationRepository->findForUser($user, $id);

        abort_if($notification === null, 404);

        return $this->notificationRepository->markRead($notification);
    }

    public function markAllRead(User $user): void
    {
        $this->notificationRepository->markAllRead($user);
    }

    /**
     * Tạo 1 thông báo trong-app (luôn), kèm gửi email nếu có $emailMailable
     * (dùng đúng địa chỉ $emailAddress nếu truyền vào — vd email công ty của
     * nhân viên, KHÔNG phải lúc nào cũng trùng $recipient->email đăng nhập).
     */
    public function send(
        User $recipient,
        string $type,
        string $title,
        string $message,
        array $data = [],
        ?Mailable $emailMailable = null,
        ?string $emailAddress = null,
    ): Notification {
        $notification = DB::transaction(function () use ($recipient, $type, $title, $message, $data) {
            $notification = $this->notificationRepository->create([
                'user_id' => $recipient->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);

            $this->notificationRepository->createDelivery([
                'notification_id' => $notification->id,
                'channel' => 'in_app',
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            return $notification;
        });

        // Bắn sự kiện realtime SAU KHI transaction đã commit — cùng quy ước
        // "post-commit" đã có ở LeaveApprovalService::decide() cho email.
        NotificationCreated::dispatch($notification);

        if ($emailMailable !== null) {
            $this->sendEmail($notification, $recipient, $emailMailable, $emailAddress);
        }

        return $notification;
    }

    private function sendEmail(Notification $notification, User $recipient, Mailable $mailable, ?string $emailAddress): void
    {
        $delivery = $this->notificationRepository->createDelivery([
            'notification_id' => $notification->id,
            'channel' => 'email',
            // "queued" (không phải "sent") — chỉ chắc chắn đã đưa vào hàng
            // đợi, chưa chắc gửi thành công (mail chạy nền qua Queue worker).
            'status' => 'queued',
        ]);

        // Mailable nào tự khai thêm method withNotificationDelivery() (nhận 1
        // int, tự lưu lại rồi cập nhật NotificationDelivery đó trong
        // failed()) thì được gắn sẵn id bản ghi delivery ở đây — Laravel tự
        // gọi failed() trên Mailable khi queue retry hết. Mailable không hỗ
        // trợ thì bỏ qua, không lỗi (method_exists() bên dưới).
        if (method_exists($mailable, 'withNotificationDelivery')) {
            $mailable->withNotificationDelivery($delivery->id);
        }

        Mail::to($emailAddress ?? $recipient->email)->queue($mailable);
    }
}
