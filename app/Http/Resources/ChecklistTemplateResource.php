<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChecklistTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'responsible' => $item->responsible,
                'due_offset_days' => $item->due_offset_days,
                'is_required' => $item->is_required,
                'auto_key' => $item->auto_key,
            ])->values(),
        ];
    }
}
