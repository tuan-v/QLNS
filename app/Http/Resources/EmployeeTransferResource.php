<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeTransferResource extends JsonResource
{
    public function toArray($request)
    {
        // index() đã with() sẵn qua Repository — loadMissing() ở đây chỉ để
        // store() (bản ghi vừa tạo, chưa nạp quan hệ nào) cũng ra đủ dữ liệu.
        $this->resource->loadMissing(['fromDepartment', 'toDepartment', 'newManager', 'oldPosition', 'newPosition', 'approver']);

        return [
            'id' => $this->id,
            'from_department' => $this->whenLoaded('fromDepartment'),
            'to_department' => $this->whenLoaded('toDepartment'),
            'old_position' => $this->whenLoaded('oldPosition'),
            'new_position' => $this->whenLoaded('newPosition'),
            'new_manager' => $this->whenLoaded(
                'newManager',
                fn () => $this->newManager ? [
                    'id' => $this->newManager->id,
                    'full_name' => $this->newManager->full_name,
                ] : null,
            ),
            'effective_date' => $this->effective_date,
            'reason' => $this->reason,
            'approved_at' => $this->approved_at,
            'approver' => $this->whenLoaded('approver', fn () => $this->approver?->user_name),
            'decision_file_url' => $this->decision_file
                ? route('employees.transfers.download', [
                    'employee' => $this->employee_id,
                    'transfer' => $this->id,
                ])
                : null,
            'created_at' => $this->created_at,
        ];
    }
}
