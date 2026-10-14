<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ReviewProjectTaskRequest;
use App\Http\Requests\Project\SaveProjectTaskRequest;
use App\Http\Resources\ProjectTaskResource;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Công việc trong dự án (2026-10-14). Mỗi task trả kèm "can" = những nút người xem được bấm,
// tính từ đúng logic quyền của Service — Frontend không tự đoán quyền.
class ProjectTaskController extends Controller
{
    public function __construct(
        private readonly ProjectTaskService $taskService,
        private readonly ProjectService $projectService,
    ) {
    }

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $tasks = $this->taskService->list($request->user(), $project, $request->only(['status', 'assignee_id']));
        $canManage = $this->projectService->canManageTeam($request->user(), $project);

        return ProjectTaskResource::collection($tasks->each(fn ($task) => $this->attachAbilities($request->user(), $task->setRelation('project', $project), $canManage)));
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;
        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        $tasks = $this->taskService->mine($employee, $request->only(['scope', 'project_id']));

        return ProjectTaskResource::collection($tasks->each(fn ($task) => $this->attachAbilities($request->user(), $task)));
    }

    public function show(Request $request, ProjectTask $task): ProjectTaskResource
    {
        return $this->respond($request->user(), $this->taskService->show($request->user(), $task));
    }

    public function store(SaveProjectTaskRequest $request, Project $project): JsonResponse
    {
        $task = $this->taskService->create($request->user(), $project, $request->validated());

        return $this->respond($request->user(), $task->load('assignee'))->response()->setStatusCode(201);
    }

    public function update(SaveProjectTaskRequest $request, ProjectTask $task): ProjectTaskResource
    {
        return $this->respond($request->user(), $this->taskService->update($request->user(), $task, $request->validated())->load('assignee'));
    }

    public function destroy(Request $request, ProjectTask $task): JsonResponse
    {
        $this->taskService->delete($request->user(), $task);

        return response()->json(['message' => 'Đã xóa công việc.']);
    }

    public function cancel(Request $request, ProjectTask $task): ProjectTaskResource
    {
        return $this->respond($request->user(), $this->taskService->cancel($request->user(), $task));
    }

    public function start(Request $request, ProjectTask $task): ProjectTaskResource
    {
        return $this->respond($request->user(), $this->taskService->start($request->user(), $task));
    }

    public function submit(Request $request, ProjectTask $task): ProjectTaskResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']], [
            'note.max' => 'Ghi chú không được vượt quá 2000 ký tự',
        ]);

        return $this->respond($request->user(), $this->taskService->submit($request->user(), $task, $data['note'] ?? null));
    }

    public function review(ReviewProjectTaskRequest $request, ProjectTask $task): ProjectTaskResource
    {
        $data = $request->validated();

        return $this->respond($request->user(), $this->taskService->review(
            $request->user(),
            $task,
            $data['decision'],
            isset($data['quality_score']) ? (int) $data['quality_score'] : null,
            $data['note'] ?? null,
        ));
    }

    private function respond(User $user, ProjectTask $task): ProjectTaskResource
    {
        return new ProjectTaskResource($this->attachAbilities($user, $task));
    }

    private function attachAbilities(User $user, ProjectTask $task, ?bool $canManage = null): ProjectTask
    {
        $project = $task->project;
        $canManage ??= $this->projectService->canManageTeam($user, $project);
        $isAssignee = $user->employee && $user->employee->id === $task->assignee_employee_id;
        $projectOpen = ! $project->isClosed();

        $task->setAttribute('can', [
            'edit' => $canManage && $projectOpen && $task->isOpen(),
            'cancel' => $canManage && $task->isOpen(),
            'delete' => $canManage && $task->status === ProjectTask::STATUS_TODO,
            'start' => $isAssignee && $task->status === ProjectTask::STATUS_TODO,
            'submit' => $isAssignee && $projectOpen && in_array($task->status, [ProjectTask::STATUS_TODO, ProjectTask::STATUS_IN_PROGRESS], true),
            'review' => $task->status === ProjectTask::STATUS_IN_REVIEW && $this->taskService->canReview($user, $task),
        ]);

        return $task;
    }
}
