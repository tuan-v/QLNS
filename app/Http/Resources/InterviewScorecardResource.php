<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewScorecardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_set_name' => $this->question_set_name,
            'pass_percent' => (float) $this->pass_percent,
            'status' => $this->status,
            'score_percent' => $this->score_percent !== null ? (float) $this->score_percent : null,
            'result' => $this->result,
            'overall_note' => $this->overall_note,
            'scored_by' => $this->whenLoaded('scorer', fn () => $this->scorer?->user_name),
            'submitted_at' => $this->submitted_at,
            'updated_at' => $this->updated_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'content' => $item->content,
                'guidance' => $item->guidance,
                'weight' => $item->weight,
                'score' => $item->score,
                'note' => $item->note,
            ])->values()),
        ];
    }
}
