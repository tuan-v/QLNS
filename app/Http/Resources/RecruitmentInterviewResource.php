<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// 1 buổi phỏng vấn cho trang "Lịch phỏng vấn".
class RecruitmentInterviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $candidate = $this->candidate;
        $opening = $candidate?->opening;

        return [
            'id' => $this->id,
            'scheduled_at' => $this->scheduled_at,
            'format' => $this->format,
            'duration_minutes' => $this->duration_minutes,
            'location' => $this->location,
            'province_code' => $this->province_code,
            'commune_code' => $this->commune_code,
            'address_detail' => $this->address_detail,
            'status' => $this->status,
            'result' => $this->result,
            'result_note' => $this->result_note,
            'confirmation_status' => $this->confirmation_status,
            'confirmation_note' => $this->confirmation_note,
            'confirmed_by' => $this->whenLoaded('confirmer', fn () => $this->confirmer?->user_name),
            'confirmed_at' => $this->confirmed_at,
            'interviewer' => $this->interviewer ? ['id' => $this->interviewer->id, 'full_name' => $this->interviewer->full_name] : null,
            'candidate' => $candidate ? [
                'id' => $candidate->id,
                'full_name' => $candidate->full_name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'status' => $candidate->status,
                // Vị trí ứng tuyển (đợt tuyển chung).
                'applied_position' => $candidate->relationLoaded('appliedPosition') ? $candidate->appliedPosition?->name : null,
            ] : null,
            'opening' => $opening ? [
                'id' => $opening->id,
                'title' => $opening->title,
                'department' => $opening->department?->name,
                'has_question_set' => $candidate ? $this->candidate->effectiveQuestionSets()[0]->isNotEmpty() : false,
            ] : null,
            'scorecard' => $this->relationLoaded('scorecard') && $this->scorecard ? [
                'id' => $this->scorecard->id,
                'status' => $this->scorecard->status,
                'score_percent' => $this->scorecard->score_percent !== null ? (float) $this->scorecard->score_percent : null,
                'result' => $this->scorecard->result,
            ] : null,
        ];
    }
}
