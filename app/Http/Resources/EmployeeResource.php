<?php

namespace App\Http\Resources;

use App\Repositories\EmployeeRepository;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EmployeeResource extends JsonResource
{
    // Cache tập ID cấp trên theo từng viewer, dùng chung cho mọi EmployeeResource
    // được tạo trong cùng 1 request (vd: mỗi dòng của index()). Không cache thì
    // mỗi dòng trong danh sách sẽ tự đi duyệt lại toàn bộ chuỗi quản lý của CÙNG
    // MỘT người xem — tốn N lần query giống hệt nhau cho N dòng, dù kết quả không đổi.
    private static array $ancestorIdsCache = [];

    private static function ancestorIdsFor(?\App\Models\Employee $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        if (! array_key_exists($viewer->id, self::$ancestorIdsCache)) {
            self::$ancestorIdsCache[$viewer->id] = app(EmployeeRepository::class)->ancestorIds($viewer);
        }

        return self::$ancestorIdsCache[$viewer->id];
    }

    // Cùng công thức LeaveRequestService::remainingDays() (private ở đó, tách
    // riêng ra đây thay vì gọi chéo Service từ Resource — 2 lớp khác trách
    // nhiệm, không nên phụ thuộc nhau chỉ vì 1 phép cộng trừ đơn giản).
    private static function leaveRemainingDays(?\App\Models\LeaveBalance $balance): ?float
    {
        if (! $balance) {
            return null;
        }

        return round(
            (float) $balance->allocated_days
                + (float) $balance->carried_forward_days
                + (float) $balance->adjusted_days
                - (float) $balance->used_days,
            2
        );
    }

    public function toArray($request)
    {
        // show()/store()/update() không đi qua EmployeeRepository::find() nên không có
        // sẵn with(...) — loadMissing() chỉ query nếu quan hệ CHƯA được nạp, nên với
        // index() (đã with() từ trước) dòng này không tốn thêm query nào.
        $this->resource->loadMissing(['department', 'position.suggestedRoles', 'manager', 'province', 'commune', 'user.roles', 'activeContract', 'currentYearLeaveBalance']);

        $viewer = $request->user()?->employee;
        $hideSensitive = in_array($this->resource->id, self::ancestorIdsFor($viewer), true);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'full_name' => $this->full_name,
            'avatar_url' => $this->avatar ? Storage::disk('public')->url($this->avatar) : null,
            'company_email' => $this->company_email,
            'employment_status' => $this->employment_status,
            'hire_date' => $this->hire_date,
            'department' => $this->whenLoaded('department'),
            'position' => $this->whenLoaded('position'),
            'manager' => $this->whenLoaded('manager', fn () => new EmployeeResource($this->manager)),
            // null = chưa có tài khoản đăng nhập (EmployeeDetail.vue dùng để
            // hiện/ẩn nút "Tạo tài khoản đăng nhập" — xem EmployeeAccountController).
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'email' => $this->user->email,
                'roles' => $this->user->roles->pluck('name'),
            ] : null),

            // Field nhạy cảm — ẩn nếu người xem là cấp dưới của nhân viên này
            // (2026-09-24, theo yêu cầu người dùng: thêm cột "Lương" ở danh
            // sách nhân viên — coi lương nhạy cảm y hệt CCCD/SĐT/ngày sinh,
            // cùng cơ chế ẩn ancestorIds() đã có, không thêm quyền riêng mới).
            'agreed_salary' => $hideSensitive ? null : $this->activeContract?->agreed_salary,
            // Cột "Nghỉ phép" ở danh sách nhân viên (2026-09-24, theo yêu cầu
            // người dùng) — coi tương tự lương, cùng cơ chế ẩn với cấp dưới.
            // remaining = allocated + carried_forward + adjusted - used, GIỐNG
            // HỆT công thức LeaveRequestService::remainingDays() (KHÔNG trừ
            // thêm các đơn đang chờ duyệt — đó là "available_days", riêng cho
            // màn tự nộp đơn tránh đăng ký chồng quỹ, không hợp cho 1 cột tổng
            // quan). Chưa có dòng LeaveBalance nào thì hiện null (Frontend ra
            // "—"), KHÔNG tự tính chiếu như targetAllocatedDays() — xem
            // comment ở Employee::currentYearLeaveBalance().
            'leave_allocated_days' => $hideSensitive ? null : $this->currentYearLeaveBalance?->allocated_days,
            'leave_remaining_days' => $hideSensitive ? null : self::leaveRemainingDays($this->currentYearLeaveBalance),
            'date_of_birth' => $hideSensitive ? null : $this->date_of_birth,
            'gender' => $hideSensitive ? null : $this->gender,
            'phone' => $hideSensitive ? null : $this->phone,
            'personal_email' => $hideSensitive ? null : $this->personal_email,
            'cccd' => $hideSensitive ? null : $this->cccd,
            'personal_tax_code' => $hideSensitive ? null : $this->personal_tax_code,
            'address_detail' => $hideSensitive ? null : $this->address_detail,
            'province' => $hideSensitive ? null : $this->whenLoaded('province'),
            'commune' => $hideSensitive ? null : $this->whenLoaded('commune'),
        ];
    }
}
