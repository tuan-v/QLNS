<?php

namespace App\Http\Resources;

use App\Services\InterviewScorecardService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewScorecardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // % từng phần + % tổng theo tỉ trọng (đang chấm thì là tạm tính, câu chưa chấm = 0).
        $breakdown = $this->relationLoaded('items') ? app(InterviewScorecardService::class)->breakdown($this->resource) : null;

        return [
            'id' => $this->id,
            'question_set_name' => $this->question_set_name,
            'pass_percent' => (float) $this->pass_percent,
            'status' => $this->status,
            'score_percent' => $this->score_percent !== null ? (float) $this->score_percent : null,
            'result' => $this->result,
            'sections' => $breakdown ? $breakdown['sections'] : null,
            'current_percent' => $breakdown ? $breakdown['percent'] : null,
            'overall_note' => $this->overall_note,
            'scored_by' => $this->whenLoaded('scorer', fn () => $this->scorer?->user_name),
            'submitted_at' => $this->submitted_at,
            'updated_at' => $this->updated_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'section_index' => $item->section_index,
                'content' => $item->content,
                'guidance' => $item->guidance,
                'weight' => $item->weight,
                'score' => $item->score,
                'note' => $item->note,
            ])->values()),
        ];
    }
}
