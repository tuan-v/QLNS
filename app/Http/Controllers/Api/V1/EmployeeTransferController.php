<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeTransfer\StoreEmployeeTransferRequest;
use App\Http\Resources\EmployeeTransferResource;
use App\Models\Employee;
use App\Models\EmployeeTransfer;
use App\Services\EmployeeTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeTransferController extends Controller
{
    public function __construct(private readonly EmployeeTransferService $employeeTransferService)
    {
    }

    public function index(Employee $employee): AnonymousResourceCollection
    {
        $transfers = $this->employeeTransferService->listForEmployee($employee);

        return EmployeeTransferResource::collection($transfers);
    }

    // Lịch sử luân chuyển của CHÍNH người đang đăng nhập — cùng lý do không
    // gắn permission:employee.view như EmployeeController::me(). Thiếu hàm
    // này thì role Employee (không còn employee.view từ khi mục 15 được làm)
    // hoàn toàn không xem được lịch sử luân chuyển của chính mình nữa — lỗ
    // hổng phát hiện lúc rà lại toàn bộ nhóm "hồ sơ CHÍNH MÌNH", vá cho khớp
    // với Hợp đồng/Tài liệu/Ca làm việc đã có sẵn mine().
    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return EmployeeTransferResource::collection($this->employeeTransferService->listForEmployee($employee));
    }

    public function store(StoreEmployeeTransferRequest $request, Employee $employee): JsonResponse
    {
        $transfer = $this->employeeTransferService->create(
            $employee,
            $request->validated(),
            $request->user()->id,
            $request->file('decision_file'),
        );

        return (new EmployeeTransferResource($transfer))->response()->setStatusCode(201);
    }

    public function download(Request $request, Employee $employee, EmployeeTransfer $transfer): StreamedResponse
    {
        if ($transfer->employee_id !== $employee->id) {
            abort(404);
        }

        // Không gắn permission:employee.view ở route — tự kiểm tra "chính
        // mình HOẶC có employee.view", cùng cách EmployeeContractController/
        // EmployeeDocumentController::download() đã làm.
        $isOwnRecord = $request->user()->employee?->id === $employee->id;
        abort_unless($isOwnRecord || $request->user()->hasPermission('employee.view'), 403);

        if (! $transfer->decision_file) {
            abort(404);
        }

        return Storage::disk('local')->download($transfer->decision_file);
    }
}
