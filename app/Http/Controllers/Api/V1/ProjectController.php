<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\SaveProjectRequest;
use App\Http\Resources\ProjectEmployeeResource;
use App\Http\Resources\ProjectResource;
use App\Models\Employee;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Dự án (2026-10-14). Quyền chi tiết (Lead / thành viên / quản lý) kiểm trong ProjectService.
class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projectService)
    {
    }

    // Quản lý thấy mọi dự án (?mine=1 để lọc dự án mình tham gia); người khác chỉ dự án mình tham gia.
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'search']) + ['mine' => $request->boolean('mine')];

        return ProjectResource::collection($this->projectService->list($request->user(), $filters));
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        return new ProjectResource($this->projectService->show($request->user(), $project));
    }

    public function store(SaveProjectRequest $request): JsonResponse
    {
        $project = $this->projectService->create($request->user(), $request->validated());

        return (new ProjectResource($this->projectService->show($request->user(), $project)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(SaveProjectRequest $request, Project $project): ProjectResource
    {
        $project = $this->projectService->update($request->user(), $project, $request->validated());

        return new ProjectResource($this->projectService->show($request->user(), $project));
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->projectService->delete($request->user(), $project);

        return response()->json(['message' => 'Đã xóa dự án.']);
    }

    public function addMembers(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
        ], [
            'employee_ids.required' => 'Vui lòng chọn ít nhất 1 nhân viên',
            'employee_ids.min' => 'Vui lòng chọn ít nhất 1 nhân viên',
        ]);

        $added = $this->projectService->addMembers($request->user(), $project, $data['employee_ids']);

        return response()->json(['message' => "Đã thêm {$added->count()} thành viên.", 'added' => $added->count()]);
    }

    public function removeMember(Request $request, Project $project, Employee $employee): JsonResponse
    {
        $this->projectService->removeMember($request->user(), $project, $employee);

        return response()->json(['message' => 'Đã xóa thành viên khỏi dự án.']);
    }

    public function memberCandidates(Request $request, Project $project): AnonymousResourceCollection
    {
        return ProjectEmployeeResource::collection(
            $this->projectService->memberCandidates($request->user(), $project, $request->string('search')->toString() ?: null),
        );
    }

    public function leadCandidates(Request $request): AnonymousResourceCollection
    {
        return ProjectEmployeeResource::collection(
            $this->projectService->leadCandidates($request->string('search')->toString() ?: null),
        );
    }
}
