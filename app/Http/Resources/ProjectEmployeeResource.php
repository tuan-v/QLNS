<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

// Thông tin rút gọn của 1 nhân viên trong màn hình dự án — chỉ tên/mã/ảnh/chức vụ
// (thành viên dự án không có quyền employee.view, không lộ hồ sơ chi tiết).
class ProjectEmployeeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'code' => $this->code,
            'avatar_url' => $this->avatar ? Storage::disk('public')->url($this->avatar) : null,
            'position' => $this->whenLoaded('position', fn () => $this->position?->name),
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
        ];
    }
}
