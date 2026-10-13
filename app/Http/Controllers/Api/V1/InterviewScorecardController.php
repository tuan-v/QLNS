<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\AssignQuestionSetsRequest;
use App\Http\Requests\Recruitment\SaveQuestionSetRequest;
use App\Http\Requests\Recruitment\SaveScorecardRequest;
use App\Http\Resources\InterviewQuestionSetResource;
use App\Http\Resources\InterviewScorecardResource;
use App\Http\Resources\RecruitmentInterviewResource;
use App\Models\InterviewQuestionSet;
use App\Models\InterviewScorecard;
use App\Models\Position;
use App\Models\RecruitmentCandidate;
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

    public function assignToOpening(AssignQuestionSetsRequest $request, RecruitmentOpening $opening): JsonResponse
    {
        $opening = $this->scorecardService->assignToOpening($opening, $request->validated('question_sets'));

        return response()->json(['data' => ['question_sets' => $this->presentSets($opening->questionSets)]]);
    }

    // Bộ câu hỏi riêng cho 1 ứng viên (rỗng = dùng mặc định theo vị trí ứng tuyển / đợt tuyển).
    public function assignToCandidate(AssignQuestionSetsRequest $request, RecruitmentCandidate $candidate): JsonResponse
    {
        $candidate = $this->scorecardService->assignToCandidate($candidate, $request->validated('question_sets'));
        [$sets, $source] = $candidate->effectiveQuestionSets();

        return response()->json(['data' => ['question_sets' => $this->presentSets($sets), 'question_set_source' => $source]]);
    }

    // Bộ câu hỏi mặc định theo chức vụ: HR + Admin xem, Admin sửa.
    public function positionDefaults(): JsonResponse
    {
        return response()->json(['data' => $this->scorecardService->positionDefaults()->map(fn (Position $position) => [
            'id' => $position->id,
            'name' => $position->name,
            'department_id' => $position->department_id,
            'department' => $position->department?->name,
            'question_sets' => $this->presentSets($position->questionSets),
        ])->values()]);
    }

    public function assignToPosition(AssignQuestionSetsRequest $request, Position $position): JsonResponse
    {
        $position = $this->scorecardService->assignToPosition($position, $request->validated('question_sets'));

        return response()->json(['data' => ['question_sets' => $this->presentSets($position->questionSets)]]);
    }

    private function presentSets($sets): array
    {
        return $sets->map(fn ($set) => [
            'id' => $set->id,
            'name' => $set->name,
            'pass_percent' => (float) $set->pass_percent,
            'weight' => (int) $set->pivot->weight,
        ])->values()->all();
    }

    // Trang chấm điểm: thông tin buổi PV + phiếu (nếu đã mở) + lý do chưa mở được.
    public function show(RecruitmentInterview $interview): JsonResponse
    {
        $interview->load('candidate.opening.department', 'confirmer:id,user_name', 'interviewer');
        $scorecard = $this->scorecardService->forInterview($interview);
        [$sets, $source] = $interview->candidate?->effectiveQuestionSets() ?? [collect(), null];

        return response()->json([
            'interview' => new RecruitmentInterviewResource($interview),
            // Các bộ (tỉ trọng) sẽ dùng khi mở phiếu — phiếu đã mở thì xem sections của phiếu.
            'question_sets' => $this->presentSets($sets),
            'question_set_source' => $source,
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
