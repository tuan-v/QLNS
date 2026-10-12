<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RecruitmentOpeningResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'department' => $this->whenLoaded('department'),
            'department_id' => $this->department_id,
            'position' => $this->whenLoaded('position'),
            'position_id' => $this->position_id,
            'contract_type' => $this->contract_type,
            'interview_question_set_id' => $this->interview_question_set_id,
            'question_set' => $this->whenLoaded('questionSet', fn () => $this->questionSet ? [
                'id' => $this->questionSet->id,
                'name' => $this->questionSet->name,
                'pass_percent' => (float) $this->questionSet->pass_percent,
            ] : null),
            'headcount' => $this->headcount,
            'approved_count' => (int) ($this->approved_count ?? 0),
            'pending_count' => (int) ($this->pending_count ?? 0),
            'candidates_count' => $this->when(isset($this->candidates_count), fn () => (int) $this->candidates_count),
            'deadline' => $this->deadline?->toDateString(),
            'is_past_deadline' => $this->isPastDeadline(),
            'description' => $this->description,
            'status' => $this->status,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->user_name),
            'candidates' => RecruitmentCandidateResource::collection($this->whenLoaded('candidates')),
            'created_at' => $this->created_at,
        ];
    }
}
