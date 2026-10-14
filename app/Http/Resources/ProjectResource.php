<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray($request)
    {
        $pointsTotal = (int) ($this->points_total ?? 0);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'lead_employee_id' => $this->lead_employee_id,
            'lead' => new ProjectEmployeeResource($this->whenLoaded('lead')),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->user_name),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($member) => [
                'id' => $member->id,
                'role' => $member->role,
                'open_tasks' => (int) ($member->open_tasks ?? 0),
                'overdue_tasks' => (int) ($member->overdue_tasks ?? 0),
                'employee' => new ProjectEmployeeResource($member->employee),
            ])->values()),
            'members_count' => (int) ($this->members_count ?? 0),
            'stats' => [
                'total' => (int) ($this->tasks_total ?? 0),
                'done' => (int) ($this->tasks_done ?? 0),
                'in_review' => (int) ($this->tasks_in_review ?? 0),
                'overdue' => (int) ($this->tasks_overdue ?? 0),
                // Tiến độ theo điểm độ khó (việc khó nặng hơn việc dễ).
                'progress' => $pointsTotal > 0 ? (int) round(((int) $this->points_done / $pointsTotal) * 100) : 0,
            ],
            'my_role' => $this->my_role,
            'can_manage_team' => (bool) ($this->can_manage_team ?? false),
            'can_edit' => (bool) ($this->can_edit ?? false),
            'created_at' => $this->created_at,
        ];
    }
}
