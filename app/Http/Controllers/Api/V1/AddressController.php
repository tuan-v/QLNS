<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Province;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Dữ liệu hành chính Tỉnh/Xã — chỉ đọc, không có Sửa/Xóa/Thêm vì đây là dữ
// liệu tham chiếu cố định (nạp 1 lần qua ProvinceCommuneSeeder), Admin không
// tự quản lý qua API.
class AddressController extends Controller
{
    public function provinces(): JsonResponse
    {
        return response()->json(Province::query()->orderBy('name')->get(['code', 'name']));
    }

    public function communes(Request $request): JsonResponse
    {
        $request->validate([
            'province_code' => ['required', 'integer', 'exists:provinces,code'],
        ]);

        $communes = Commune::query()
            ->where('province_code', $request->integer('province_code'))
            ->orderBy('name')
            ->get(['code', 'name', 'province_code']);

        return response()->json($communes);
    }
}
