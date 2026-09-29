<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResignationRequestResource;
use App\Models\ResignationRequest;
use App\Services\ResignationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ResignationRequestController extends Controller
{
    public function __construct(private readonly ResignationService $resignationService)
    {
    }

    // Luôn nộp cho CHÍNH người gọi API — không nhận employee_id từ client.
    public function store(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        $data = $request->validate([
            'last_working_date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'last_working_date.required' => 'Vui lòng chọn ngày làm việc cuối cùng',
            'last_working_date.after_or_equal' => 'Ngày làm việc cuối cùng không được ở trong quá khứ',
            'reason.required' => 'Vui lòng nhập lý do nghỉ việc',
            'reason.max' => 'Lý do không được vượt quá 2000 ký tự',
        ]);

        $resignation = $this->resignationService->create($employee, $data);

        return (new ResignationRequestResource($resignation))->response()->setStatusCode(201);
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return ResignationRequestResource::collection($this->resignationService->listForEmployee($employee));
    }

    public function cancel(Request $request, ResignationRequest $resignationRequest): ResignationRequestResource
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return new ResignationRequestResource($this->resignationService->cancel($employee, $resignationRequest));
    }

    // HR thấy mọi đơn, Manager chỉ đơn của nhân viên mình quản lý trực tiếp —
    // lọc bên trong ResignationService (không chỉ ẩn ở Frontend).
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['status', 'search']);
        $perPage = (int) $request->input('per_page', 10);

        return ResignationRequestResource::collection($this->resignationService->list($request->user(), $filters, $perPage));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $resignation = $this->resignationService->findVisible($request->user(), $id);

        return (new ResignationRequestResource($resignation))
            ->additional(['can_decide' => $this->resignationService->canDecide($request->user(), $resignation)])
            ->response();
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $resignation = $this->resignationService->findVisible($request->user(), $id);

        $data = $request->validate([
            'status' => ['required', Rule::in([ResignationRequest::STATUS_APPROVED, ResignationRequest::STATUS_REJECTED])],
            // Từ chối bắt buộc có lý do để nhân viên biết vì sao.
            'note' => ['nullable', 'string', 'max:1000', 'required_if:status,rejected'],
        ], [
            'note.required_if' => 'Vui lòng nhập lý do từ chối',
        ]);

        $resignation = $this->resignationService->decide($resignation, $data['status'], $data['note'] ?? null, $request->user());

        return (new ResignationRequestResource($resignation))->response();
    }
}
