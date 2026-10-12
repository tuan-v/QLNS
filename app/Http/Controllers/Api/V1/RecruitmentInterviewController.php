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
            'confirmation_status' => ['nullable', 'in:'.implode(',', RecruitmentInterview::CONFIRMATION_STATUSES)],
            'opening_id' => ['nullable', 'integer'],
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

    public function confirm(RecordInterviewConfirmationRequest $request, RecruitmentInterview $interview): RecruitmentInterviewResource
    {
        $interview = $this->interviewService->recordConfirmation($interview, $request->input('status'), $request->input('note'), $request->user());

        return new RecruitmentInterviewResource($interview->load('candidate.opening.department', 'confirmer:id,user_name', 'interviewer'));
    }
}
