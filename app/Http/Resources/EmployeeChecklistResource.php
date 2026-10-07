<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EmployeeChecklistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->items;
        $done = $items->whereNotNull('completed_at')->count();
        $pending = $items->whereNull('completed_at');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'template_name' => $this->template_name,
            'reference_date' => $this->reference_date?->toDateString(),
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            'resignation_request_id' => $this->resignation_request_id,
            'created_at' => $this->created_at,
            'employee' => $this->employee ? [
                'id' => $this->employee->id,
                'code' => $this->employee->code,
                'full_name' => $this->employee->full_name,
                'avatar_url' => $this->employee->avatar ? Storage::disk('public')->url($this->employee->avatar) : null,
                'department' => $this->employee->department?->name,
                'position' => $this->employee->position?->name,
                'employment_status' => $this->employee->employment_status,
            ] : null,
            'progress' => [
                'done' => $done,
                'total' => $items->count(),
                'percent' => $items->count() ? (int) round($done * 100 / $items->count()) : 0,
            ],
            'overdue_count' => $pending->filter(fn ($item) => $item->due_date && $item->due_date->lt(today()))->count(),
            'next_due_date' => $pending->whereNotNull('due_date')->sortBy('due_date')->first()?->due_date?->toDateString(),
            'can_manage' => (bool) $this->getAttribute('can_manage'),
            'items' => EmployeeChecklistItemResource::collection($items),
        ];
    }
}
