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
            'note' => $this->note,
            'status' => $this->status,
            'cv_original_name' => $this->cv_original_name,
            'cv_url' => route('recruitment.candidates.cv', ['candidate' => $this->id]),
            'submitter' => $this->whenLoaded('submitter', fn () => $this->submitter?->user_name),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->user_name),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'interviews' => $this->whenLoaded('interviews', fn () => $this->interviews->map(fn ($interview) => [
                'id' => $interview->id,
                'scheduled_at' => $interview->scheduled_at,
                'format' => $interview->format,
                'location' => $interview->location,
                'province_code' => $interview->province_code,
                'commune_code' => $interview->commune_code,
                'address_detail' => $interview->address_detail,
                'interviewer' => $interview->interviewer ? ['id' => $interview->interviewer->id, 'full_name' => $interview->interviewer->full_name] : null,
                'status' => $interview->status,
                'result' => $interview->result,
                'result_note' => $interview->result_note,
            ])->values()),
            'hired_employee' => $this->whenLoaded('hiredEmployee'),
            'offer' => $this->whenLoaded('latestOffer', fn () => $this->latestOffer ? new RecruitmentOfferResource($this->latestOffer) : null),
            'created_at' => $this->created_at,
        ];
    }
}
