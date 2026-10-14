<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'code' => $this->project->code,
                'name' => $this->project->name,
                'status' => $this->project->status,
            ]),
            'title' => $this->title,
            'description' => $this->description,
            'assignee_employee_id' => $this->assignee_employee_id,
            'assignee' => new ProjectEmployeeResource($this->whenLoaded('assignee')),
            'priority' => $this->priority,
            'points' => $this->points,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'is_overdue' => $this->isOverdue(),
            'on_time' => $this->finishedOnTime(),
            'started_at' => $this->started_at,
            'submitted_at' => $this->submitted_at,
            'submission_note' => $this->submission_note,
            'completed_at' => $this->completed_at,
            'quality_score' => $this->quality_score,
            'review_note' => $this->review_note,
            'return_count' => $this->return_count,
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->user_name),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->user_name),
            // Nút thao tác hiển thị theo quyền thật (ProjectTaskController gắn vào).
            'can' => $this->when(isset($this->can), fn () => $this->can),
            'created_at' => $this->created_at,
        ];
    }
}
