<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BankAccount\ReviewBankAccountRequest;
use App\Http\Requests\BankAccount\SaveBankAccountRequest;
use App\Http\Resources\EmployeeBankAccountResource;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Services\EmployeeBankAccountService;
use App\Support\VietnamBanks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// Tài khoản ngân hàng nhận lương — HR (/{employee}/bank-accounts, employee.update) và
// nhân viên tự phục vụ (/me/bank-accounts). Quy tắc: EmployeeBankAccountService.
class EmployeeBankAccountController extends Controller
{
    public function __construct(private readonly EmployeeBankAccountService $bankAccountService)
    {
    }

    public function banks(): JsonResponse
    {
        return response()->json([
            'data' => collect(VietnamBanks::ALL)->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values(),
        ]);
    }

    public function index(Employee $employee): AnonymousResourceCollection
    {
        return EmployeeBankAccountResource::collection($this->bankAccountService->listFor($employee));
    }

    public function store(SaveBankAccountRequest $request, Employee $employee): JsonResponse
    {
        return (new EmployeeBankAccountResource($this->bankAccountService->create($employee, $request->validated(), $request->user(), true)))
            ->response()->setStatusCode(201);
    }

    public function update(SaveBankAccountRequest $request, Employee $employee, EmployeeBankAccount $bankAccount): EmployeeBankAccountResource
    {
        return new EmployeeBankAccountResource($this->bankAccountService->update($employee, $bankAccount, $request->validated(), $request->user(), true));
    }

    public function review(ReviewBankAccountRequest $request, Employee $employee, EmployeeBankAccount $bankAccount): EmployeeBankAccountResource
    {
        return new EmployeeBankAccountResource($this->bankAccountService->review(
            $employee,
            $bankAccount,
            $request->input('status'),
            $request->input('review_note'),
            $request->user(),
        ));
    }

    public function setPrimary(Employee $employee, EmployeeBankAccount $bankAccount): EmployeeBankAccountResource
    {
        return new EmployeeBankAccountResource($this->bankAccountService->setPrimary($employee, $bankAccount));
    }

    public function destroy(Employee $employee, EmployeeBankAccount $bankAccount): JsonResponse
    {
        $this->bankAccountService->delete($employee, $bankAccount, true);

        return response()->json(['message' => 'Đã xóa tài khoản ngân hàng.']);
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        return EmployeeBankAccountResource::collection($this->bankAccountService->listFor($this->myEmployee($request)));
    }

    public function storeMine(SaveBankAccountRequest $request): JsonResponse
    {
        return (new EmployeeBankAccountResource($this->bankAccountService->create($this->myEmployee($request), $request->validated(), $request->user(), false)))
            ->response()->setStatusCode(201);
    }

    public function updateMine(SaveBankAccountRequest $request, EmployeeBankAccount $bankAccount): EmployeeBankAccountResource
    {
        return new EmployeeBankAccountResource($this->bankAccountService->update($this->myEmployee($request), $bankAccount, $request->validated(), $request->user(), false));
    }

    public function setPrimaryMine(Request $request, EmployeeBankAccount $bankAccount): EmployeeBankAccountResource
    {
        return new EmployeeBankAccountResource($this->bankAccountService->setPrimary($this->myEmployee($request), $bankAccount));
    }

    public function destroyMine(Request $request, EmployeeBankAccount $bankAccount): JsonResponse
    {
        $this->bankAccountService->delete($this->myEmployee($request), $bankAccount, false);

        return response()->json(['message' => 'Đã xóa tài khoản ngân hàng.']);
    }

    private function myEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return $employee;
    }
}
