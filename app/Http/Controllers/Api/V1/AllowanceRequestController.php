<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAllowanceRequest;
use App\Services\AllowanceChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Đề xuất thay đổi phụ cấp (HR gửi, Admin duyệt) — tab "Đề xuất" của trang /phu-cap.
class AllowanceRequestController extends Controller
{
    public function __construct(private readonly AllowanceChangeService $changes)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,cancelled,all'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->changes->list($request->user(), $filters));
    }

    public function approve(Request $request): JsonResponse
    {
        $data = $this->validateDecision($request, false);
        $result = $this->changes->approve($request->user(), $data['ids'], $data['note'] ?? null);

        return response()->json($result + ['message' => "Đã duyệt {$result['done']} đề xuất."]);
    }

    public function reject(Request $request): JsonResponse
    {
        $data = $this->validateDecision($request, true);
        $result = $this->changes->reject($request->user(), $data['ids'], $data['note']);

        return response()->json($result + ['message' => "Đã từ chối {$result['done']} đề xuất."]);
    }

    public function destroy(Request $request, EmployeeAllowanceRequest $allowanceRequest): JsonResponse
    {
        $this->changes->cancel($request->user(), $allowanceRequest);

        return response()->json(['message' => 'Đã rút lại đề xuất.']);
    }

    private function validateDecision(Request $request, bool $noteRequired): array
    {
        return $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'note' => [$noteRequired ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'ids.required' => 'Vui lòng chọn đề xuất',
            'note.required' => 'Vui lòng ghi lý do từ chối',
        ]);
    }
}
