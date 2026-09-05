<?php

namespace App\Services;

use App\Models\Employee;
use App\Repositories\EmployeeRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function __construct(private readonly EmployeeRepository $employeeRepository)
    {
    }
    public function list(): LengthAwarePaginator
    {
        return $this->employeeRepository->paginate();
    }
    public function create(array $data): Employee
    {
        $data['code'] = $this->generateCode();
        return DB::transaction(fn () => $this->employeeRepository->create($data));
    }
    private function generateCode(): string
    {
        $prefix = 'NV';
        $lastNumber = Employee::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->pluck('code')
            ->filter(fn (string $code) => preg_match('/^' . $prefix . '(\d+)$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();
        $nextNumber = ($lastNumber ?? 0) + 1;
        return $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }
    public function update(Employee $employee, array $data): Employee
    {
        if (
            array_key_exists('manager_id', $data)
            && $data['manager_id'] !== null
            && $this->employeeRepository->wouldCreateCycle($employee->id, $data['manager_id'])
        ) {
            throw ValidationException::withMessages([
                'manager_id' => 'Không thể chọn nhân viên này làm quản lý vì sẽ tạo vòng lặp trong cơ cấu tổ chức.',
            ]);
        }

        return $this->employeeRepository->update($employee, $data);
    }

    public function delete(Employee $employee): void
    {
        $this->employeeRepository->delete($employee);
    }


    public function updateAvatar(Employee $employee, UploadedFile $file): Employee
    {
        $oldPath = $employee->avatar;

        $path = $file->store('avatars', 'public');
        $this->employeeRepository->update($employee, ['avatar' => $path]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $employee;
    }
}
