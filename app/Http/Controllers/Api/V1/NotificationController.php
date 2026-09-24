<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $this->notificationService->listForUser($request->user());

        return NotificationResource::collection($notifications)
            ->additional(['unread_count' => $this->notificationService->unreadCountForUser($request->user())]);
    }

    // Toàn bộ thông báo trong công ty (không lọc theo user hiện tại) — gate
    // "notification.view_all" ở route, dùng cho trang admin "Toàn công ty".
    public function all(Request $request): AnonymousResourceCollection
    {
        $notifications = $this->notificationService->listAll($request->only(['type', 'search']));

        return NotificationResource::collection($notifications);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $notification = $this->notificationService->markRead($request->user(), $notification);

        return response()->json(new NotificationResource($notification));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->notificationService->markAllRead($request->user());

        return response()->json(['message' => 'Đã đánh dấu tất cả đã đọc.']);
    }
}
