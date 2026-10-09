<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\RespondRecruitmentOfferRequest;
use App\Http\Requests\Recruitment\ReviewRecruitmentOfferRequest;
use App\Http\Requests\Recruitment\StoreRecruitmentOfferRequest;
use App\Http\Resources\RecruitmentOfferResource;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOffer;
use App\Services\RecruitmentOfferService;
use Illuminate\Http\JsonResponse;

// Thư mời nhận việc — xem RecruitmentOfferService. Nội bộ: soạn/rút (recruitment.manage),
// duyệt (recruitment.approve). Công khai (không đăng nhập): ứng viên xem/trả lời qua mã link.
class RecruitmentOfferController extends Controller
{
    public function __construct(private readonly RecruitmentOfferService $offerService)
    {
    }

    public function store(StoreRecruitmentOfferRequest $request, RecruitmentCandidate $candidate): JsonResponse
    {
        $offer = $this->offerService->create($candidate, $request->validated(), $request->user());

        return (new RecruitmentOfferResource($offer->load('creator:id,user_name')))->response()->setStatusCode(201);
    }

    public function review(ReviewRecruitmentOfferRequest $request, RecruitmentOffer $offer): RecruitmentOfferResource
    {
        $offer = $this->offerService->review($offer, $request->input('status') === 'approved', $request->input('review_note'), $request->user());

        return new RecruitmentOfferResource($offer->load('creator:id,user_name', 'reviewer:id,user_name'));
    }

    public function withdraw(RecruitmentOffer $offer): RecruitmentOfferResource
    {
        return new RecruitmentOfferResource($this->offerService->withdraw($offer));
    }

    // --- Công khai cho ứng viên ---

    public function showPublic(string $token): JsonResponse
    {
        return response()->json(['data' => $this->publicPayload($this->offerService->findByToken($token))]);
    }

    public function respondPublic(RespondRecruitmentOfferRequest $request, string $token): JsonResponse
    {
        $offer = $this->offerService->respond($token, $request->input('decision') === 'accept', $request->input('note'));

        return response()->json(['data' => $this->publicPayload($offer)]);
    }

    // Chỉ những gì ứng viên cần thấy — không lộ ghi chú nội bộ, người duyệt, id nội bộ.
    private function publicPayload(RecruitmentOffer $offer): array
    {
        $opening = $offer->candidate->opening;

        return [
            'company' => config('app.name'),
            'candidate_name' => $offer->candidate->full_name,
            'title' => $opening->title,
            'department' => $opening->department?->name,
            'position' => $opening->position?->name,
            'contract_type' => $offer->contract_type,
            'salary' => (float) $offer->salary,
            'start_date' => $offer->start_date->toDateString(),
            'response_deadline' => $offer->response_deadline->toDateString(),
            'message' => $offer->message,
            'status' => $offer->status,
            'can_respond' => $offer->status === RecruitmentOffer::STATUS_SENT,
            'closed_message' => $offer->status === RecruitmentOffer::STATUS_SENT ? null : $this->offerService->closedMessage($offer),
            'responded_at' => $offer->responded_at,
        ];
    }
}
