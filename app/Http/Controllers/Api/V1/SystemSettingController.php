<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Attendance\OfficeLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Trang "Cài đặt hệ thống" — vị trí công ty dùng để tính khoảng cách lúc chấm công (2026-10-22).
class SystemSettingController extends Controller
{
    public function __construct(private readonly OfficeLocation $officeLocation)
    {
    }

    public function officeLocation(): JsonResponse
    {
        return response()->json(['data' => $this->officeLocation->get()]);
    }

    public function updateOfficeLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ], [
            'latitude.required' => 'Vui lòng nhập vĩ độ',
            'latitude.between' => 'Vĩ độ phải trong khoảng -90 đến 90',
            'longitude.required' => 'Vui lòng nhập kinh độ',
            'longitude.between' => 'Kinh độ phải trong khoảng -180 đến 180',
        ]);

        $location = $this->officeLocation->set((float) $data['latitude'], (float) $data['longitude'], $request->user());

        return response()->json(['data' => $location, 'message' => 'Đã lưu vị trí công ty.']);
    }
}
