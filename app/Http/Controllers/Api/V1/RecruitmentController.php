<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\BulkReviewRecruitmentCandidatesRequest;
use App\Http\Requests\Recruitment\BulkScheduleRecruitmentInterviewsRequest;
use App\Http\Requests\Recruitment\RecordRecruitmentInterviewResultRequest;
use App\Http\Requests\Recruitment\ReviewRecruitmentCandidateRequest;
use App\Http\Requests\Recruitment\SaveRecruitmentOpeningRequest;
use App\Http\Requests\Recruitment\ScheduleRecruitmentInterviewRequest;
use App\Http\Requests\Recruitment\StoreRecruitmentCandidateRequest;
use App\Http\Resources\RecruitmentCandidateResource;
use App\Http\Resources\RecruitmentOpeningResource;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOpening;
use App\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Tuyển dụng — xem RecruitmentService cho toàn bộ quy tắc. Quyền: recruitment.manage
// (HR + Admin) cho đợt tuyển/CV/phỏng vấn; recruitment.approve (Admin) duyệt CV.
class RecruitmentController extends Controller
{
    public function __construct(private readonly RecruitmentService $recruitmentService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return RecruitmentOpeningResource::collection($this->recruitmentService->listOpenings(
            $request->only(['status', 'search']),
            (int) $request->input('per_page', 15),
        ));
    }

    public function store(SaveRecruitmentOpeningRequest $request): JsonResponse
    {
        $opening = $this->recruitmentService->createOpening($request->validated(), $request->user());

        return (new RecruitmentOpeningResource($this->recruitmentService->showOpening($opening)))
            ->response()->setStatusCode(201);
    }

    public function show(RecruitmentOpening $opening): RecruitmentOpeningResource
    {
        return new RecruitmentOpeningResource($this->recruitmentService->showOpening($opening));
    }

    public function update(SaveRecruitmentOpeningRequest $request, RecruitmentOpening $opening): RecruitmentOpeningResource
    {
        $opening = $this->recruitmentService->updateOpening($opening, $request->validated());

        return new RecruitmentOpeningResource($this->recruitmentService->showOpening($opening));
    }

    public function close(RecruitmentOpening $opening): RecruitmentOpeningResource
    {
        return new RecruitmentOpeningResource($this->recruitmentService->showOpening($this->recruitmentService->closeOpening($opening)));
    }

    public function reopen(RecruitmentOpening $opening): RecruitmentOpeningResource
    {
        return new RecruitmentOpeningResource($this->recruitmentService->showOpening($this->recruitmentService->reopenOpening($opening)));
    }

    public function destroy(RecruitmentOpening $opening): JsonResponse
    {
        $this->recruitmentService->deleteOpening($opening);

        return response()->json(['message' => 'Đã xóa đợt tuyển.']);
    }

    public function storeCandidate(StoreRecruitmentCandidateRequest $request, RecruitmentOpening $opening): JsonResponse
    {
        $candidate = $this->recruitmentService->addCandidate(
            $opening,
            $request->safe()->except('cv_file'),
            $request->file('cv_file'),
            $request->user(),
        );

        return (new RecruitmentCandidateResource($candidate))->response()->setStatusCode(201);
    }

    // Kiểm tra trùng email/SĐT ngay khi rời ô ở form Tải CV (cùng ý tưởng
    // EmployeeController::checkUnique) — câu báo lấy từ đúng hàm dùng lúc lưu.
    public function checkDuplicate(Request $request, RecruitmentOpening $opening): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', 'in:email,phone'],
            'value' => ['required', 'string', 'max:255'],
        ]);

        $message = $this->recruitmentService->duplicateMessage($opening, $data['field'], $data['value']);

        return response()->json(['available' => $message === null, 'message' => $message]);
    }

    public function review(ReviewRecruitmentCandidateRequest $request, RecruitmentCandidate $candidate): RecruitmentCandidateResource
    {
        return new RecruitmentCandidateResource($this->recruitmentService->reviewCandidate(
            $candidate,
            $request->validated('status'),
            $request->validated('review_note'),
            $request->user(),
        ));
    }

    public function bulkReview(BulkReviewRecruitmentCandidatesRequest $request): JsonResponse
    {
        return response()->json($this->recruitmentService->bulkReview(
            $request->validated('candidate_ids'),
            $request->validated('status'),
            $request->validated('review_note'),
            $request->user(),
        ));
    }

    public function bulkScheduleInterviews(BulkScheduleRecruitmentInterviewsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->recruitmentService->bulkScheduleInterviews(
            $data['candidate_ids'],
            collect($data)->except(['candidate_ids'])->all(),
            $request->user(),
        ));
    }

    public function scheduleInterview(ScheduleRecruitmentInterviewRequest $request, RecruitmentCandidate $candidate): RecruitmentCandidateResource
    {
        $this->recruitmentService->scheduleInterview($candidate, $request->validated(), $request->user());

        return new RecruitmentCandidateResource($candidate->fresh()->load('interviews.interviewer'));
    }

    public function recordResult(RecordRecruitmentInterviewResultRequest $request, RecruitmentCandidate $candidate): RecruitmentCandidateResource
    {
        $candidate = $this->recruitmentService->recordResult(
            $candidate,
            $request->validated('result'),
            $request->validated('result_note'),
        );

        return new RecruitmentCandidateResource($candidate->load('interviews.interviewer'));
    }

    public function destroyCandidate(RecruitmentCandidate $candidate): JsonResponse
    {
        $this->recruitmentService->deleteCandidate($candidate);

        return response()->json(['message' => 'Đã xóa CV.']);
    }

    public function downloadCv(RecruitmentCandidate $candidate): StreamedResponse
    {
        $disk = Storage::disk(RecruitmentService::CV_DISK);
        abort_unless($disk->exists($candidate->cv_file), 404, 'Không tìm thấy tệp CV.');

        return $disk->response($candidate->cv_file, $candidate->cv_original_name, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
