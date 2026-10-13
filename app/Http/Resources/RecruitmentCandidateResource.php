<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RecruitmentCandidateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'recruitment_opening_id' => $this->recruitment_opening_id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'applied_position' => $this->whenLoaded('appliedPosition', fn () => $this->appliedPosition ? [
                'id' => $this->appliedPosition->id,
                'name' => $this->appliedPosition->name,
                'department_id' => $this->appliedPosition->department_id,
            ] : null),
            'applied_position_id' => $this->applied_position_id,
            'desired_contract_type' => $this->desired_contract_type,
            'source' => $this->source,
            'source_note' => $this->source_note,
            // Bộ câu hỏi dùng khi chấm + nguồn (candidate|position|opening) — chỉ khi đã nạp đủ quan hệ.
            ...($this->relationLoaded('questionSets') ? $this->questionSetPayload() : []),
            'note' => $this->note,
            'status' => $this->status,
            // Giai đoạn hiển thị thống nhất: { key, label, reason } (RecruitmentCandidate::stageFor()).
            'stage' => $this->resource->stage(),
            'cv_original_name' => $this->cv_original_name,
            'cv_url' => route('recruitment.candidates.cv', ['candidate' => $this->id]),
            'submitter' => $this->whenLoaded('submitter', fn () => $this->submitter?->user_name),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->user_name),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'interviews' => $this->whenLoaded('interviews', fn () => $this->interviews->map(fn ($interview) => [
                'id' => $interview->id,
                'scheduled_at' => $interview->scheduled_at,
                'duration_minutes' => $interview->duration_minutes,
                'format' => $interview->format,
                'location' => $interview->location,
                'province_code' => $interview->province_code,
                'commune_code' => $interview->commune_code,
                'address_detail' => $interview->address_detail,
                'interviewer' => $interview->interviewer ? ['id' => $interview->interviewer->id, 'full_name' => $interview->interviewer->full_name] : null,
                'status' => $interview->status,
                'result' => $interview->result,
                'result_note' => $interview->result_note,
                'confirmation_status' => $interview->confirmation_status,
                'confirmation_note' => $interview->confirmation_note,
            ])->values()),
            'hired_employee' => $this->whenLoaded('hiredEmployee'),
            'previous_applications_count' => $this->getAttribute('previous_applications_count'),
            'offer' => $this->whenLoaded('latestOffer', fn () => $this->latestOffer ? new RecruitmentOfferResource($this->latestOffer) : null),
            'created_at' => $this->created_at,
        ];
    }

    private function questionSetPayload(): array
    {
        [$sets, $source] = $this->resource->effectiveQuestionSets();

        return [
            'question_sets' => $sets->map(fn ($set) => [
                'id' => $set->id,
                'name' => $set->name,
                'pass_percent' => (float) $set->pass_percent,
                'weight' => (int) $set->pivot->weight,
            ])->values(),
            'question_set_source' => $source,
        ];
    }
}
