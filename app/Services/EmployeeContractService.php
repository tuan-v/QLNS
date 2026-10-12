<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Repositories\EmployeeContractRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeContractService
{
    // Trạng thái nhân viên suy ra từ LOẠI hợp đồng đang có hiệu lực (2026-09-29,
    // theo yêu cầu người dùng: bỏ chọn tay "Trạng thái làm việc", đổi nhãn
    // 'active' thành "Chính thức" cho khỏi nhầm với "đang đi làm").
    public const EMPLOYMENT_STATUS_BY_CONTRACT_TYPE = [
        'thu_viec' => 'probation',
        'chinh_thuc' => 'active',
        // Thực tập sinh: không đóng bảo hiểm; ngày lễ có hưởng lương hay không theo
        // cờ intern_paid của từng dịp nghỉ (PayrollService::standardWorkDaysFor()).
        'thuc_tap' => 'intern',
    ];

    public function __construct(
        private readonly EmployeeContractRepository $employeeContractRepository,
        private readonly EmployeeAccountService $employeeAccountService,
    ) {
    }

    // Gọi mỗi khi 1 hợp đồng BẮT ĐẦU có hiệu lực (tạo mới với start_date <=
    // hôm nay, hoặc job contracts:activate-pending kích hoạt) — trạng thái
    // nhân viên theo đúng loại hợp đồng đó. Ký hợp đồng mới cho người đã nghỉ
    // việc/chấm dứt HĐ = tuyển lại -> xóa luôn termination_date cũ.
    public function applyEmploymentStatus(EmployeeContract $contract): void
    {
        $employee = $contract->employee;
        $status = self::EMPLOYMENT_STATUS_BY_CONTRACT_TYPE[$contract->contract_type] ?? null;

        if ($employee === null || $status === null) {
            return;
        }

        $attributes = [
            'employment_status' => $status,
            'termination_date' => null,
        ];
        // Hợp đồng thử việc là căn cứ cho ngày hết thử việc — ghi đè ô nhập tay
        // trong hồ sơ để 2 nơi không lệch nhau (cảnh báo "quá hạn thử việc" sai).
        if ($contract->contract_type === 'thu_viec' && $contract->end_date) {
            $attributes['probation_end_date'] = $contract->end_date;
        }

        $employee->forceFill($attributes)->save();

        // Tuyển lại người từng bị khóa tài khoản khi nghỉ việc -> mở lại.
        $this->employeeAccountService->reactivateAccountOf($employee);
        // Thực tập <-> thử việc/chính thức: đổi vai trò Intern <-> Employee.
        $this->employeeAccountService->syncInternRole($employee);
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->employeeContractRepository->listByEmployee($employee);
    }

    // $file nullable (2026-09-24, theo yêu cầu người dùng) — Hợp đồng ĐẦU
    // TIÊN giờ tự tạo luôn lúc tạo nhân viên (xem EmployeeService::create()),
    // lúc đó CHƯA có file bản giấy đã ký để đính kèm; HR upload file thật sau
    // ở tab "Hợp đồng" (route riêng, chưa làm — vẫn phải qua form đầy đủ có
    // `contract_file` bắt buộc như cũ khi ký hợp đồng MỚI/tiếp theo, xem
    // StoreEmployeeContractRequest — chỉ lần tạo TỰ ĐỘNG này mới được bỏ qua).
    public function create(Employee $employee, array $data, ?UploadedFile $file = null): EmployeeContract
    {
        $data['employee_id'] = $employee->id;
        $data['contract_number'] = $this->generateContractNumber($data['contract_type']);
        $data['contract_file_path'] = $file?->store('contracts', 'local');
        // Lương đóng BHXH = LUÔN bằng lương thỏa thuận (2026-09-24, theo yêu
        // cầu người dùng: "lương đóng bh sẽ tính là lương cb luôn không tách
        // ra") — không còn là ô nhập riêng, ghi đè bất kể client gửi gì lên
        // (cùng cách contract_number/status tự quyết định, không tin dữ liệu
        // client). Áp dụng CHUNG cho mọi hợp đồng — tự tạo lúc tạo nhân viên
        // (EmployeeService::create()) lẫn tạo tay ở tab "Hợp đồng"
        // (EmployeeContractController::store()).
        $data['insurance_salary'] = $data['agreed_salary'];

        // Ký TRƯỚC ngày bắt đầu (vd renew hợp đồng sớm cho nhân viên) thì hợp
        // đồng mới CHƯA được coi là hiệu lực ngay — status "pending", không
        // phải "active". Bug thật đã vấp: nếu cứ set active ngay, hợp đồng cũ
        // (đang thật sự áp dụng) bị auto-supersede sang expired NGAY LÚC TẠO,
        // dù còn cả tháng nữa hợp đồng mới mới thật sự bắt đầu — hiển thị sai
        // chiều (hợp đồng đang dùng thật thì báo hết hạn) và HR không chấm
        // dứt được đúng hợp đồng đang áp dụng (terminate() chỉ cho từ active).
        // Đúng ngày bắt đầu, job hằng ngày contracts:activate-pending (xem
        // app/Console/Commands/ActivatePendingContracts.php) mới chuyển
        // pending -> active và supersede hợp đồng cũ đúng lúc đó.
        $startsInFuture = $data['start_date'] > now()->toDateString();
        $data['status'] = $startsInFuture ? 'pending' : 'active';

        return DB::transaction(function () use ($employee, $data, $startsInFuture) {
            if (! $startsInFuture) {
                // Hợp đồng mới ký thì hợp đồng active CŨ (nếu có) coi như đã bị
                // thay thế — tự chuyển sang "expired" luôn, không cần HR vào tay
                // từng cái. Không dùng terminate() (dành cho chấm dứt CHỦ ĐỘNG
                // giữa chừng, ghi terminated_at) vì đây là kết thúc TỰ NHIÊN do có
                // hợp đồng kế tiếp, không phải quyết định chấm dứt.
                $employee->contracts()
                    ->where('status', 'active')
                    ->get()
                    ->each(fn (EmployeeContract $old) => $this->employeeContractRepository->update($old, ['status' => 'expired']));
            }

            $contract = $this->employeeContractRepository->create($data);

            if (! $startsInFuture) {
                $this->applyEmploymentStatus($contract);
            }

            return $contract;
        });
    }

    /**
     * Bổ sung dữ liệu còn THIẾU của 1 hợp đồng đã tạo: tệp PDF bản ký, ngày
     * kết thúc, ngày ký. Đây là "route riêng" mà comment ở create() nhắc tới —
     * hợp đồng ĐẦU TIÊN tự tạo lúc thêm nhân viên không có 3 thứ này, trước
     * đây không có đường nào bổ sung được nữa.
     *
     * Ngày kết thúc / ngày ký CHỈ điền được khi đang trống — đã ghi nhận thì
     * không sửa lại, vì đó là dữ kiện của bản hợp đồng đã ký. Riêng TỆP PDF
     * thay được kèm lý do (xem phần dưới). Muốn đổi điều khoản (lương, loại
     * hợp đồng, ngày bắt đầu) thì vẫn phải ký hợp đồng MỚI qua create().
     */
    public function fillMissing(EmployeeContract $contract, array $data, ?UploadedFile $file = null): EmployeeContract
    {
        $labels = ['end_date' => 'Ngày kết thúc', 'signed_at' => 'Ngày ký'];
        $changes = [];

        foreach ($labels as $field => $label) {
            $value = $data[$field] ?? null;

            if ($value === null) {
                continue;
            }

            if ($contract->{$field} !== null) {
                throw ValidationException::withMessages([
                    $field => "{$label} của hợp đồng này đã có, không sửa lại được — cần đổi thì ký hợp đồng mới.",
                ]);
            }

            $changes[$field] = $value;
        }

        // Tệp PDF thì KHÁC 2 trường ngày ở trên: cho thay cả khi đã có, vì
        // upload nhầm bản scan là lỗi thao tác rất dễ xảy ra và cấm tuyệt đối
        // chỉ để lại dữ liệu sai vĩnh viễn chứ không bảo vệ được gì. Đổi lại,
        // phải nhập lý do (UpdateEmployeeContractRequest bắt buộc) và:
        //   - KHÔNG xóa tệp cũ khỏi đĩa — còn nguyên để đối chiếu/khôi phục;
        //   - ghi 1 dòng audit riêng nêu rõ tệp cũ → tệp mới + lý do, bên cạnh
        //     dòng "updated" mà AuditObserver tự ghi (ai/IP/lúc nào).
        $replacedFrom = null;

        if ($file !== null) {
            $replacedFrom = $contract->contract_file_path;
            $changes['contract_file_path'] = $file->store('contracts', 'local');
        }

        if ($changes === []) {
            throw ValidationException::withMessages([
                'contract' => 'Không có dữ liệu nào để bổ sung.',
            ]);
        }

        return DB::transaction(function () use ($contract, $changes, $replacedFrom, $data) {
            $updated = $this->employeeContractRepository->update($contract, $changes);

            if ($replacedFrom !== null) {
                $this->logFileReplacement($updated, $replacedFrom, $data['replace_reason'] ?? null);
            }

            return $updated;
        });
    }

    /**
     * Một dòng audit DÀNH RIÊNG cho việc thay tệp hợp đồng. AuditObserver đã
     * tự ghi dòng "updated" (ai, IP, lúc nào, cột nào đổi) nhưng KHÔNG mang
     * được lý do — mà lý do mới là thứ giải thích được vì sao bản ký đính kèm
     * đổi. Gom cả tệp cũ, tệp mới và lý do vào 1 dòng để sau này tra cứu chỉ
     * cần đọc đúng dòng đó.
     */
    private function logFileReplacement(EmployeeContract $contract, string $previousPath, ?string $reason): void
    {
        AuditLog::create([
            'action' => 'contract_file_replaced',
            'auditable_type' => EmployeeContract::class,
            'auditable_id' => $contract->getKey(),
            'old_data' => ['contract_file_path' => $previousPath],
            'new_data' => [
                'contract_file_path' => $contract->contract_file_path,
                'replace_reason' => $reason,
            ],
            'user_id' => request()->user()?->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'http_method' => request()->method(),
            'url' => request()->fullUrl(),
            'request_id' => (string) Str::uuid(),
        ]);
    }

    // Chấm dứt hợp đồng giữa chừng (HR chủ động, khác "expired" tự nhiên hết
    // hạn/bị thay thế) — chỉ cho phép từ "active", ghi lại terminated_at để
    // biết chính xác ngày dừng thực tế (có thể sớm hơn end_date trên hợp đồng).
    public function terminate(EmployeeContract $contract): EmployeeContract
    {
        if ($contract->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'Chỉ có thể chấm dứt hợp đồng đang còn hiệu lực.',
            ]);
        }

        return DB::transaction(function () use ($contract) {
            $contract = $this->employeeContractRepository->update($contract, [
                'status' => 'terminated',
                'terminated_at' => now()->toDateString(),
            ]);

            // Chấm dứt ĐÚNG hợp đồng đang áp dụng mà không còn hợp đồng nào
            // khác đang/sắp hiệu lực -> nhân viên "Đã chấm dứt HĐ" (2026-09-29,
            // trạng thái giờ đi theo hợp đồng, không còn chọn tay).
            $employee = $contract->employee;
            $hasOtherContract = $employee?->contracts()
                ->whereKeyNot($contract->id)
                ->whereIn('status', ['active', 'pending'])
                ->exists();

            if ($employee && ! $hasOtherContract) {
                $employee->forceFill([
                    'employment_status' => 'terminated',
                    'termination_date' => $contract->terminated_at,
                ])->save();

                // Chấm dứt hợp đồng cuối cùng = hết quan hệ lao động -> khóa tài khoản.
                $this->employeeAccountService->deactivateAccountOf($employee);
            }

            return $contract;
        });
    }

    // Số hợp đồng do hệ thống tự sinh theo LOẠI hợp đồng, không nhận từ client:
    // "HDTV-" (thử việc) / "HDCT-" (chính thức) + số thứ tự 3 chữ số riêng cho
    // từng tiền tố — cùng cơ chế PositionService::generateCode()/
    // WorkShiftService::generateCode(), chỉ khác tiền tố phụ thuộc contract_type
    // thay vì cố định.
    private function generateContractNumber(string $contractType): string
    {
        $prefix = $contractType === 'chinh_thuc' ? 'HDCT' : 'HDTV';

        $lastNumber = EmployeeContract::withTrashed()
            ->where('contract_number', 'like', $prefix . '-%')
            ->pluck('contract_number')
            ->filter(fn (string $code) => preg_match('/^' . $prefix . '-(\d+)$/', $code) === 1)
            ->map(fn (string $code) => (int) substr($code, strlen($prefix) + 1))
            ->max();

        $nextNumber = ($lastNumber ?? 0) + 1;

        return $prefix . '-' . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }
}
