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
            'contract_type' => $this->contract_type,
            'salary' => (float) $this->salary,
            'start_date' => $this->start_date?->toDateString(),
            'response_deadline' => $this->response_deadline?->toDateString(),
            'message' => $this->message,
            'status' => $this->status,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->user_name),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->user_name),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'sent_at' => $this->sent_at,
            'responded_at' => $this->responded_at,
            'response_note' => $this->response_note,
            'created_at' => $this->created_at,
        ];
    }
}
