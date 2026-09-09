<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkShift\StoreWorkShiftRequest;
use App\Http\Requests\WorkShift\UpdateWorkShiftRequest;
use App\Models\WorkShift;
use App\Services\WorkShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkShiftController extends Controller
{
    public function __construct(private readonly WorkShiftService $workShiftService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);

        return response()->json($this->workShiftService->list($perPage));
    }

    public function store(StoreWorkShiftRequest $request): JsonResponse
    {
        $workShift = $this->workShiftService->create($request->validated());

        return response()->json($workShift, 201);
    }

    public function update(UpdateWorkShiftRequest $request, WorkShift $workShift): JsonResponse
    {
        $workShift = $this->workShiftService->update($workShift, $request->validated());

        return response()->json($workShift);
    }

    public function destroy(WorkShift $workShift): JsonResponse
    {
        $this->workShiftService->delete($workShift);

        return response()->json(null, 204);
    }
}
