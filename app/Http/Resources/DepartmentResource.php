<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'manager_id' => $this->manager_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            // Bọc qua EmployeeResource để tự động ẩn field nhạy cảm (CCCD, SĐT...)
            // với người xem là cấp dưới của Trưởng phòng — cùng cơ chế redaction
            // đang dùng cho field "manager" trong EmployeeResource.
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? new EmployeeResource($this->manager) : null),
            'children' => $this->whenLoaded('children', fn () => DepartmentResource::collection($this->children)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
