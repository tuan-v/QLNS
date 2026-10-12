<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\SaveQuestionSetRequest;
use App\Http\Requests\Recruitment\SaveScorecardRequest;
use App\Http\Resources\InterviewQuestionSetResource;
use App\Http\Resources\InterviewScorecardResource;
use App\Http\Resources\RecruitmentInterviewResource;
use App\Models\InterviewQuestionSet;
use App\Models\InterviewScorecard;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOpening;
use App\Services\InterviewScorecardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Bộ câu hỏi + phiếu chấm phỏng vấn — xem InterviewScorecardService. Bộ câu hỏi: Admin
// (recruitment.approve) sửa, HR + Admin xem. Chấm: recruitment.manage hoặc approve.
class InterviewScorecardController extends Controller
{
    public function __construct(private readonly InterviewScorecardService $scorecardService)
    {
    }

    public function sets(): AnonymousResourceCollection
    {
        return InterviewQuestionSetResource::collection($this->scorecardService->sets());
    }

    public function storeSet(SaveQuestionSetRequest $request): JsonResponse
    {
        return (new InterviewQuestionSetResource($this->scorecardService->saveSet($request->validated(), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function updateSet(SaveQuestionSetRequest $request, InterviewQuestionSet $set): InterviewQuestionSetResource
    {
        return new InterviewQuestionSetResource($this->scorecardService->saveSet($request->validated(), $request->user(), $set));
    }

    public function destroySet(InterviewQuestionSet $set): JsonResponse
    {
        $this->scorecardService->deleteSet($set);

        return response()->json(['message' => 'Đã xóa bộ câu hỏi.']);
    }

    public function assignToOpening(Request $request, RecruitmentOpening $opening): JsonResponse
    {
        $validated = $request->validate(['interview_question_set_id' => ['nullable', 'integer']]);
        $opening = $this->scorecardService->assignToOpening($opening, $validated['interview_question_set_id'] ?? null);

        return response()->json(['data' => ['interview_question_set_id' => $opening->interview_question_set_id]]);
    }

    // Trang chấm điểm: thông tin buổi PV + phiếu (nếu đã mở) + lý do chưa mở được.
    public function show(RecruitmentInterview $interview): JsonResponse
    {
        $interview->load('candidate.opening.department', 'candidate.opening.questionSet:id,name,pass_percent', 'confirmer:id,user_name', 'interviewer');
        $scorecard = $this->scorecardService->forInterview($interview);
        $set = $interview->candidate?->opening?->questionSet;

        return response()->json([
            'interview' => new RecruitmentInterviewResource($interview),
            'question_set' => $set ? ['id' => $set->id, 'name' => $set->name, 'pass_percent' => (float) $set->pass_percent] : null,
            'scorecard' => $scorecard ? new InterviewScorecardResource($scorecard) : null,
            'start_blocker' => $scorecard ? null : $this->scorecardService->startBlocker($interview),
        ]);
    }

    public function start(Request $request, RecruitmentInterview $interview): InterviewScorecardResource
    {
        return new InterviewScorecardResource($this->scorecardService->start($interview, $request->user()));
    }

    public function save(SaveScorecardRequest $request, InterviewScorecard $scorecard): InterviewScorecardResource
    {
        return new InterviewScorecardResource($this->scorecardService->saveDraft($scorecard, $request->validated(), $request->user()));
    }

    public function submit(SaveScorecardRequest $request, InterviewScorecard $scorecard): InterviewScorecardResource
    {
        return new InterviewScorecardResource($this->scorecardService->submit($scorecard, $request->validated(), $request->user()));
    }
}
