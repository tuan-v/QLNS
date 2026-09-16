<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\GeneratePayrollRequest;
use App\Http\Resources\PayrollDetailResource;
use App\Http\Resources\PayrollResource;
use App\Models\Employee;
use App\Models\Payroll;
use App\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payrollService)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return PayrollResource::collection($this->payrollService->list($request->only(['year', 'status'])));
    }
    // Phiếu lương của CHÍNH người đang đăng nhập — cùng cách các mine() khác
    // (EmployeeContractController, EmployeeTransferController...) không gắn
    // permission:payroll.view_all. Chỉ trả kỳ ĐÃ CHỐT (closed/paid) — kỳ
    // "processing" còn có thể bị HR sửa số liệu, chưa nên xem là bản chính thức.
    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;
        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return PayrollDetailResource::collection($this->payrollService->listPayslipsForEmployee($employee));
    }

    // Lịch sử phiếu lương của 1 nhân viên BẤT KỲ (khác mine() — chỉ chính
    // mình) — dùng cho tab "Lương / Phép" ở Chi tiết nhân viên phía HR.
    public function forEmployee(Employee $employee): AnonymousResourceCollection
    {
        return PayrollDetailResource::collection($this->payrollService->listPayslipsForEmployee($employee));
    }

    public function show(Payroll $payroll): PayrollResource
    {
        return new PayrollResource($this->payrollService->find($payroll->id));
    }

    public function generate(GeneratePayrollRequest $request): JsonResponse
    {
        $payroll = $this->payrollService->generateForPeriod(
            $request->integer('month'),
            $request->integer('year'),
            $request->user(),
        );

        // ->response()->setStatusCode(201), KHÔNG dùng response()->json($resource, 201):
        // response()->json() không đi qua cơ chế bọc "data" tự động của JsonResource,
        // sẽ trả JSON không có key "data" — LỆCH với show()/close()/markAsPaid() bên
        // dưới (đều trả Resource trực tiếp, Laravel tự bọc "data"). Bug thật phát hiện
        // qua PayrollControllerTest.php, không phải suy đoán.
        return (new PayrollResource($payroll))->response()->setStatusCode(201);
    }

    public function close(Payroll $payroll, Request $request): PayrollResource
    {
        return new PayrollResource($this->payrollService->close($payroll, $request->user()));
    }

    public function markAsPaid(Payroll $payroll): PayrollResource
    {
        return new PayrollResource($this->payrollService->markAsPaid($payroll));
    }
}
