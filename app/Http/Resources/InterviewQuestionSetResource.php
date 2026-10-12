<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewQuestionSetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'pass_percent' => (float) $this->pass_percent,
            'is_active' => $this->is_active,
            'openings_count' => $this->whenCounted('openings'),
            'questions' => $this->whenLoaded('questions', fn () => $this->questions->map(fn ($q) => [
                'id' => $q->id,
                'content' => $q->content,
                'guidance' => $q->guidance,
                'weight' => $q->weight,
            ])->values()),
        ];
    }
}
