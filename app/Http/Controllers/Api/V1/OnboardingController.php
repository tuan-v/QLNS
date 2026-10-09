<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AddChecklistItemRequest;
use App\Http\Requests\Onboarding\SaveChecklistTemplateRequest;
use App\Http\Requests\Onboarding\StoreEmployeeChecklistRequest;
use App\Http\Requests\Onboarding\UpdateChecklistItemRequest;
use App\Http\Resources\ChecklistTemplateResource;
use App\Http\Resources\EmployeeChecklistResource;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistItem;
use App\Services\ChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Onboarding / Offboarding — xem ChecklistService cho toàn bộ quy tắc.
class OnboardingController extends Controller
{
    public function __construct(private readonly ChecklistService $checklistService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return EmployeeChecklistResource::collection($this->checklistService->list(
            $request->user(),
            $request->only(['type', 'status', 'search']),
            (int) $request->input('per_page', 15),
        ));
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        return EmployeeChecklistResource::collection($this->checklistService->mine($request->user()));
    }

    public function show(Request $request, EmployeeChecklist $checklist): EmployeeChecklistResource
    {
        return new EmployeeChecklistResource($this->checklistService->show($request->user(), $checklist));
    }

    public function store(StoreEmployeeChecklistRequest $request): JsonResponse
    {
        $checklist = $this->checklistService->createManually(
            Employee::findOrFail($request->integer('employee_id')),
            $request->input('type'),
            $request->filled('template_id') ? $request->integer('template_id') : null,
            $request->user(),
        );

        return (new EmployeeChecklistResource($this->checklistService->show($request->user(), $checklist)))
            ->response()->setStatusCode(201);
    }

    public function cancel(Request $request, EmployeeChecklist $checklist): EmployeeChecklistResource
    {
        return new EmployeeChecklistResource($this->checklistService->show($request->user(), $this->checklistService->cancel($checklist)));
    }

    public function addItem(AddChecklistItemRequest $request, EmployeeChecklist $checklist): EmployeeChecklistResource
    {
        $this->checklistService->addItem($checklist, $request->validated());

        return new EmployeeChecklistResource($this->checklistService->show($request->user(), $checklist->fresh()));
    }

    public function updateItem(UpdateChecklistItemRequest $request, EmployeeChecklistItem $item): EmployeeChecklistResource
    {
        return new EmployeeChecklistResource($this->checklistService->toggleItem(
            $request->user(),
            $item,
            $request->boolean('done'),
            $request->input('note'),
        ));
    }

    public function destroyItem(Request $request, EmployeeChecklistItem $item): EmployeeChecklistResource
    {
        $checklist = $item->checklist;
        $this->checklistService->deleteItem($item);

        return new EmployeeChecklistResource($this->checklistService->show($request->user(), $checklist->fresh()));
    }

    public function templates(Request $request): AnonymousResourceCollection
    {
        return ChecklistTemplateResource::collection($this->checklistService->templates($request->input('type')));
    }

    public function storeTemplate(SaveChecklistTemplateRequest $request): JsonResponse
    {
        return (new ChecklistTemplateResource($this->checklistService->saveTemplate($request->validated())))
            ->response()->setStatusCode(201);
    }

    public function updateTemplate(SaveChecklistTemplateRequest $request, ChecklistTemplate $template): ChecklistTemplateResource
    {
        return new ChecklistTemplateResource($this->checklistService->saveTemplate($request->validated(), $template));
    }

    public function destroyTemplate(ChecklistTemplate $template): JsonResponse
    {
        $this->checklistService->deleteTemplate($template);

        return response()->json(['message' => 'Đã xóa mẫu checklist.']);
    }
}
