<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Position\StorePositionRequest;
use App\Http\Requests\Position\UpdatePositionRequest;
use App\Models\Position;
use App\Services\PositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function __construct(private readonly PositionService $positionService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['department_id']);
        $perPage = (int) $request->input('per_page', 15);

        return response()->json($this->positionService->list($filters, $perPage));
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $position = $this->positionService->create($request->validated());

        return response()->json($position, 201);
    }

    public function update(UpdatePositionRequest $request, Position $position): JsonResponse
    {
        $position = $this->positionService->update($position, $request->validated());

        return response()->json($position);
    }

    public function destroy(Position $position): JsonResponse
    {
        $this->positionService->delete($position);

        return response()->json(null, 204);
    }
}
