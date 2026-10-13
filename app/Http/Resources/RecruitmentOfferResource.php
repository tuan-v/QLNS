<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Offer cho trang nội bộ (HR/Admin). Không bao giờ trả mã link (token_hash ẩn ở Model).
class RecruitmentOfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'department_id' => $this->department_id,
            'position_id' => $this->position_id,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'position' => $this->whenLoaded('position', fn () => $this->position?->name),
            'contract_type' => $this->contract_type,
            'salary' => (float) $this->salary,
            'start_date' => $this->start_date?->toDateString(),
            'response_deadline' => $this->response_deadline?->toDateString(),
            'message' => $this->message,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'status' => $this->status,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->user_name),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->user_name),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'sent_at' => $this->sent_at,
            'responded_at' => $this->responded_at,
            'response_note' => $this->response_note,
            'response_recorded_by' => $this->whenLoaded('responseRecorder', fn () => $this->responseRecorder?->user_name),
            'created_at' => $this->created_at,
        ];
    }
}
