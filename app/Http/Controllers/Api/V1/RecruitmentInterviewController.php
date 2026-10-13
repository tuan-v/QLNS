<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\RecordInterviewConfirmationRequest;
use App\Http\Resources\RecruitmentInterviewResource;
use App\Models\RecruitmentInterview;
use App\Services\RecruitmentInterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Trang "Lịch phỏng vấn" — xem RecruitmentInterviewService. Xem: HR + Admin;
// ghi nhận phản hồi ứng viên: HR (recruitment.manage).
class RecruitmentInterviewController extends Controller
{
    public function __construct(private readonly RecruitmentInterviewService $interviewService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            // Nhóm buổi PV (RecruitmentInterviewService::BUCKETS) + người phỏng vấn ("none" = chưa phân công).
            'bucket' => ['nullable', 'in:'.implode(',', RecruitmentInterviewService::BUCKETS)],
            'opening_id' => ['nullable', 'integer'],
            'interviewer_id' => ['nullable', 'regex:/^(none|\d+)$/'],
        ], [
            'month.required' => 'Thiếu tháng cần xem',
            'month.date_format' => 'Tháng không hợp lệ (dạng YYYY-MM)',
        ]);

        $result = $this->interviewService->month($validated['month'], $validated);

        return response()->json([
            'data' => RecruitmentInterviewResource::collection($result['interviews']),
            'summary' => $result['summary'],
        ]);
    }

    // Người có thể phân công phỏng vấn: nhân viên đang làm việc (ô chọn ở form hẹn lịch).
    public function interviewers(): JsonResponse
    {
        return response()->json(['data' => $this->interviewService->interviewers()]);
    }

    public function confirm(RecordInterviewConfirmationRequest $request, RecruitmentInterview $interview): RecruitmentInterviewResource
    {
        $interview = $this->interviewService->recordConfirmation($interview, $request->input('status'), $request->input('note'), $request->user());

        return new RecruitmentInterviewResource($interview->load('candidate.opening.department', 'confirmer:id,user_name', 'interviewer'));
    }
}
