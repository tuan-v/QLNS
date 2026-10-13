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
use Illuminate\Http\Request;

// Thư mời nhận việc — xem RecruitmentOfferService. Soạn / rút / ghi nhận phản hồi của ứng viên
// (recruitment.manage), duyệt (recruitment.approve). Không còn trang công khai cho ứng viên.
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

    // Giá trị điền sẵn form soạn offer (người liên hệ = HR đang soạn, email = email công ty).
    public function defaults(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->offerService->defaults($request->user())]);
    }

    public function review(ReviewRecruitmentOfferRequest $request, RecruitmentOffer $offer): RecruitmentOfferResource
    {
        $offer = $this->offerService->review($offer, $request->input('status') === 'approved', $request->input('review_note'), $request->user());

        return new RecruitmentOfferResource($offer->load('creator:id,user_name', 'reviewer:id,user_name'));
    }

    // Sửa offer đang chờ duyệt (HR hoặc Admin lúc xem duyệt).
    public function update(StoreRecruitmentOfferRequest $request, RecruitmentOffer $offer): RecruitmentOfferResource
    {
        $offer = $this->offerService->update($offer, $request->validated(), $request->user());

        return new RecruitmentOfferResource($offer->load('creator:id,user_name', 'department:id,name', 'position:id,name'));
    }

    public function withdraw(RecruitmentOffer $offer): RecruitmentOfferResource
    {
        return new RecruitmentOfferResource($this->offerService->withdraw($offer));
    }

    // HR ghi nhận ứng viên chấp nhận / từ chối (ứng viên báo qua điện thoại hoặc email).
    public function recordResponse(RespondRecruitmentOfferRequest $request, RecruitmentOffer $offer): RecruitmentOfferResource
    {
        $offer = $this->offerService->recordResponse($offer, $request->input('decision') === 'accept', $request->input('note'), $request->user());

        return new RecruitmentOfferResource($offer->load('creator:id,user_name', 'reviewer:id,user_name', 'responseRecorder:id,user_name'));
    }
}
