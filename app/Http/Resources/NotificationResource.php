<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
            // Chỉ có khi nạp qua paginateAll() (trang admin "Toàn công ty") —
            // danh sách "của tôi" không nạp quan hệ này, tự động không hiện.
            'recipient' => $this->whenLoaded('user', fn () => [
                'user_id' => $this->user->id,
                'user_name' => $this->user->user_name,
                'employee_name' => $this->user->employee?->full_name,
                'employee_code' => $this->user->employee?->code,
            ]),
        ];
    }
}
