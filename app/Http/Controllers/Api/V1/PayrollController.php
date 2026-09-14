<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\GeneratePayrollRequest;
use App\Http\Resources\PayrollResource;
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
