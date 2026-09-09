<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceLocation\StoreAttendanceLocationRequest;
use App\Http\Requests\AttendanceLocation\UpdateAttendanceLocationRequest;
use App\Models\AttendanceLocation;
use App\Services\AttendanceLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceLocationController extends Controller
{
    public function __construct(private readonly AttendanceLocationService $attendanceLocationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);

        return response()->json($this->attendanceLocationService->list($perPage));
    }

    public function store(StoreAttendanceLocationRequest $request): JsonResponse
    {
        $attendanceLocation = $this->attendanceLocationService->create($request->validated());

        return response()->json($attendanceLocation, 201);
    }

    public function update(UpdateAttendanceLocationRequest $request, AttendanceLocation $attendanceLocation): JsonResponse
    {
        $attendanceLocation = $this->attendanceLocationService->update($attendanceLocation, $request->validated());

        return response()->json($attendanceLocation);
    }

    public function destroy(AttendanceLocation $attendanceLocation): JsonResponse
    {
        $this->attendanceLocationService->delete($attendanceLocation);

        return response()->json(null, 204);
    }
}
