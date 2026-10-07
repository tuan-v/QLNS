<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeChecklistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'responsible' => $this->responsible,
            'due_date' => $this->due_date?->toDateString(),
            'is_required' => $this->is_required,
            'auto_key' => $this->auto_key,
            'completed_at' => $this->completed_at,
            'completed_by' => $this->whenLoaded('completer', fn () => $this->completer?->user_name),
            'note' => $this->note,
            'is_overdue' => ! $this->completed_at && $this->due_date && $this->due_date->lt(today()),
            'can_toggle' => (bool) $this->getAttribute('can_toggle'),
        ];
    }
}
