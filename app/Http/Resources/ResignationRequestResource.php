<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ResignationRequestResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'last_working_date' => $this->last_working_date?->toDateString(),
            'reason' => $this->reason,
            // Đủ ngày báo trước theo luật -> chỉ là thông báo, không cần duyệt.
            'requires_approval' => (bool) $this->requires_approval,
            'notice_days_required' => $this->notice_days_required,
            'notice_days_given' => $this->notice_days_given,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at,
            'applied_at' => $this->applied_at,
            'created_at' => $this->created_at,
            'decider' => $this->whenLoaded('decider', fn () => $this->decider ? [
                'id' => $this->decider->id,
                'user_name' => $this->decider->user_name,
            ] : null),
            // Đủ thông tin để HR/Manager đọc TOÀN BỘ trước khi duyệt (2026-09-29,
            // theo yêu cầu người dùng) — gom ở đây, không bắt Frontend gọi thêm
            // API hồ sơ nhân viên (Manager có thể không xem được hồ sơ đầy đủ).
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'code' => $this->employee->code,
                'full_name' => $this->employee->full_name,
                'avatar_url' => $this->employee->avatar ? Storage::disk('public')->url($this->employee->avatar) : null,
                'company_email' => $this->employee->company_email,
                'employment_status' => $this->employee->employment_status,
                'hire_date' => $this->employee->hire_date?->toDateString(),
                'termination_date' => $this->employee->termination_date?->toDateString(),
                'department' => $this->employee->department?->name,
                'position' => $this->employee->position?->name,
                'manager' => $this->employee->relationLoaded('manager') ? $this->employee->manager?->full_name : null,
                'contract' => $this->employee->relationLoaded('activeContract') && $this->employee->activeContract ? [
                    'contract_number' => $this->employee->activeContract->contract_number,
                    'contract_type' => $this->employee->activeContract->contract_type,
                    'start_date' => $this->employee->activeContract->start_date?->toDateString(),
                    'end_date' => $this->employee->activeContract->end_date?->toDateString(),
                ] : null,
            ]),
        ];
    }
}
