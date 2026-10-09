<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\User;
use App\Support\VietnamBanks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Tài khoản ngân hàng nhận lương (Kế hoạch Ngày 21 — bảng có từ đầu, API/giao diện bổ
// sung theo yêu cầu người dùng). Quy tắc:
//  - Nhân viên tự thêm ở "Hồ sơ của tôi" -> "Chờ xác nhận"; HR (employee.update) xác
//    nhận hoặc từ chối (kèm lý do). HR tự thêm thì coi như đã xác nhận.
//  - Chỉ tài khoản ĐÃ XÁC NHẬN mới làm được tài khoản chính (nhận lương); tài khoản xác
//    nhận đầu tiên tự thành tài khoản chính. Luôn tối đa 1 tài khoản chính.
//  - Nhân viên chỉ sửa được tài khoản chờ xác nhận/bị từ chối (sửa xong quay về chờ);
//    tài khoản đã xác nhận muốn đổi thì thêm tài khoản mới — tránh lặng lẽ đổi nơi nhận lương.
//  - Không xóa được tài khoản chính phía nhân viên; HR xóa tài khoản chính thì tự chọn
//    tài khoản đã xác nhận khác (cũ nhất) làm tài khoản chính.
//  - Chống trùng: cùng ngân hàng + số tài khoản trong hồ sơ, hoặc đang thuộc nhân viên khác.
class EmployeeBankAccountService
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    /** @return Collection<int, EmployeeBankAccount> */
    public function listFor(Employee $employee): Collection
    {
        return $employee->bankAccounts()
            ->with('verifier:id,user_name')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    public function create(Employee $employee, array $data, User $actor, bool $byHr): EmployeeBankAccount
    {
        $data = $this->normalize($data);
        $this->assertNotDuplicate($employee, $data);

        $account = DB::transaction(function () use ($employee, $data, $actor, $byHr) {
            $account = $employee->bankAccounts()->create($data + [
                'status' => $byHr ? EmployeeBankAccount::STATUS_VERIFIED : EmployeeBankAccount::STATUS_PENDING,
                'created_by' => $actor->id,
                'verified_by' => $byHr ? $actor->id : null,
                'verified_at' => $byHr ? now() : null,
                'is_primary' => false,
            ]);
            $this->ensurePrimary($employee);

            return $account;
        });

        if (! $byHr) {
            $this->notifyHr($employee, $account, $actor, 'thêm');
        }

        return $account->fresh('verifier');
    }

    public function update(Employee $employee, EmployeeBankAccount $account, array $data, User $actor, bool $byHr): EmployeeBankAccount
    {
        $this->assertBelongsTo($employee, $account);

        if (! $byHr && $account->status === EmployeeBankAccount::STATUS_VERIFIED) {
            throw ValidationException::withMessages([
                'account_number' => 'Tài khoản đã được xác nhận không sửa được. Muốn đổi tài khoản nhận lương, hãy thêm tài khoản mới.',
            ]);
        }

        $data = $this->normalize($data);
        $this->assertNotDuplicate($employee, $data, $account->id);

        $account->update($byHr
            ? $data + ['status' => EmployeeBankAccount::STATUS_VERIFIED, 'verified_by' => $actor->id, 'verified_at' => now(), 'review_note' => null]
            : $data + ['status' => EmployeeBankAccount::STATUS_PENDING, 'verified_by' => null, 'verified_at' => null, 'review_note' => null]);

        if ($byHr) {
            $this->ensurePrimary($employee);
        } else {
            $this->notifyHr($employee, $account, $actor, 'sửa');
        }

        return $account->fresh('verifier');
    }

    public function review(Employee $employee, EmployeeBankAccount $account, string $status, ?string $note, User $actor): EmployeeBankAccount
    {
        $this->assertBelongsTo($employee, $account);

        if ($account->status !== EmployeeBankAccount::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'Tài khoản này không ở trạng thái chờ xác nhận.']);
        }

        DB::transaction(function () use ($employee, $account, $status, $note, $actor) {
            $account->update([
                'status' => $status,
                'review_note' => $note,
                'verified_by' => $actor->id,
                'verified_at' => now(),
            ]);
            $this->ensurePrimary($employee);
        });

        $this->notifyEmployee($employee, $account->fresh());

        return $account->fresh('verifier');
    }

    public function setPrimary(Employee $employee, EmployeeBankAccount $account): EmployeeBankAccount
    {
        $this->assertBelongsTo($employee, $account);

        if ($account->status !== EmployeeBankAccount::STATUS_VERIFIED) {
            throw ValidationException::withMessages(['is_primary' => 'Chỉ tài khoản đã được HR xác nhận mới đặt làm tài khoản nhận lương.']);
        }

        DB::transaction(function () use ($employee, $account) {
            $employee->bankAccounts()->whereKeyNot($account->id)->where('is_primary', true)->get()
                ->each(fn (EmployeeBankAccount $other) => $other->update(['is_primary' => false]));
            $account->update(['is_primary' => true]);
        });

        return $account->fresh('verifier');
    }

    public function delete(Employee $employee, EmployeeBankAccount $account, bool $byHr): void
    {
        $this->assertBelongsTo($employee, $account);

        if (! $byHr && $account->is_primary) {
            throw ValidationException::withMessages([
                'is_primary' => 'Không xóa được tài khoản nhận lương. Hãy đặt tài khoản khác làm tài khoản nhận lương trước.',
            ]);
        }

        DB::transaction(function () use ($employee, $account) {
            $account->update(['is_primary' => false]);
            $account->delete();
            $this->ensurePrimary($employee);
        });
    }

    // Chưa có tài khoản chính mà đã có tài khoản xác nhận -> chọn cái cũ nhất.
    private function ensurePrimary(Employee $employee): void
    {
        if ($employee->bankAccounts()->where('is_primary', true)->exists()) {
            return;
        }

        $employee->bankAccounts()
            ->where('status', EmployeeBankAccount::STATUS_VERIFIED)
            ->orderBy('id')
            ->first()
            ?->update(['is_primary' => true]);
    }

    private function normalize(array $data): array
    {
        return [
            'bank_code' => $data['bank_code'],
            'bank_name' => VietnamBanks::name($data['bank_code']),
            'bank_branch' => $data['bank_branch'] ?? null,
            'account_number' => $data['account_number'],
            'account_holder' => $data['account_holder'],
        ];
    }

    private function assertNotDuplicate(Employee $employee, array $data, ?int $ignoreId = null): void
    {
        $same = EmployeeBankAccount::query()
            ->where('bank_code', $data['bank_code'])
            ->where('account_number', $data['account_number'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        if (! $same) {
            return;
        }

        throw ValidationException::withMessages([
            'account_number' => $same->employee_id === $employee->id
                ? 'Tài khoản này đã có trong hồ sơ.'
                : 'Số tài khoản này đang thuộc hồ sơ của nhân viên khác.',
        ]);
    }

    private function assertBelongsTo(Employee $employee, EmployeeBankAccount $account): void
    {
        abort_if($account->employee_id !== $employee->id, 404);
    }

    private function notifyHr(Employee $employee, EmployeeBankAccount $account, User $actor, string $action): void
    {
        User::withPermission('employee.update')->get()
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->each(fn (User $user) => $this->notificationService->send(
                $user,
                'bank_account.pending',
                'Tài khoản ngân hàng cần xác nhận',
                "{$employee->full_name} vừa {$action} tài khoản ngân hàng nhận lương ({$account->bank_code} · {$account->account_number}).",
                ['employee_id' => $employee->id, 'bank_account_id' => $account->id],
            ));
    }

    private function notifyEmployee(Employee $employee, EmployeeBankAccount $account): void
    {
        if (! $employee->user) {
            return;
        }

        $verified = $account->status === EmployeeBankAccount::STATUS_VERIFIED;
        $message = $verified
            ? "Tài khoản {$account->bank_code} · {$account->account_number} đã được xác nhận".($account->is_primary ? ' và dùng để nhận lương.' : '.')
            : "Tài khoản {$account->bank_code} · {$account->account_number} chưa được chấp nhận. Lý do: {$account->review_note}";

        $this->notificationService->send(
            $employee->user,
            'bank_account.reviewed',
            $verified ? 'Tài khoản ngân hàng đã được xác nhận' : 'Tài khoản ngân hàng bị từ chối',
            $message,
            ['bank_account_id' => $account->id, 'status' => $verified ? 'approved' : 'rejected'],
        );
    }
}
