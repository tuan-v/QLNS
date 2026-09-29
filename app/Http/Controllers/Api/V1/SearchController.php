<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $searchService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q'));

        // Dưới 2 ký tự chưa đủ để tìm có nghĩa (dễ khớp cả trăm bản ghi, vừa
        // nặng vừa vô ích cho 1 API gọi liên tục theo từng lần gõ phím) — trả
        // mảng rỗng ngay, không cần chạm DB.
        if (mb_strlen($query) < 2) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->searchService->search($request->user(), $query)]);
    }
}
