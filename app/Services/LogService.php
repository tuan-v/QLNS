<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Observers\AuditObserver;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

// Nhật ký hoạt động (tài liệu yêu cầu mục 9 "Audit Log": LogController/LogService, API
// /api/v1/logs; Kế hoạch Ngày 09). Dữ liệu ghi tự động bởi AuditObserver (thêm/sửa/xóa)
// + sự kiện đăng nhập (recordAuthEvent — phần mở rộng, người dùng duyệt 2026-10-15).
// Chỉ Admin xem (quyền system.audit_log). CCCD/MST/số tài khoản che còn 4 số cuối.
class LogService
{
    public const AUTH_TYPE = 'auth';

    public const ACTIONS = [
        'created' => 'Thêm',
        'updated' => 'Sửa',
        'deleted' => 'Xóa',
        'login' => 'Đăng nhập',
        'login_failed' => 'Đăng nhập sai',
        'login_locked' => 'Khóa tài khoản',
        'logout' => 'Đăng xuất',
        'password_changed' => 'Đổi mật khẩu',
    ];

    // Tên dễ đọc của từng loại dữ liệu được ghi log.
    public const MODULES = [
        self::AUTH_TYPE => 'Đăng nhập',
        'App\Models\Employee' => 'Nhân viên',
        'App\Models\EmployeeContract' => 'Hợp đồng',
        'App\Models\EmployeeBankAccount' => 'Tài khoản ngân hàng',
        'App\Models\EmployeeDocument' => 'Tài liệu nhân viên',
        'App\Models\EmployeeTransfer' => 'Điều chuyển',
        'App\Models\EmployeeShiftAssignment' => 'Phân ca',
        'App\Models\Department' => 'Phòng ban',
        'App\Models\Position' => 'Chức vụ',
        'App\Models\Role' => 'Vai trò',
        'App\Models\Permission' => 'Quyền',
        'App\Models\WorkShift' => 'Ca làm việc',
        'App\Models\Attendance' => 'Chấm công',
        'App\Models\AttendanceLog' => 'Lượt chấm công',
        'App\Models\AttendanceAdjustment' => 'Điều chỉnh công',
        'App\Models\LeaveRequest' => 'Đơn nghỉ phép',
        'App\Models\LeaveApproval' => 'Duyệt nghỉ phép',
        'App\Models\LeaveBalance' => 'Số dư phép',
        'App\Models\LeaveType' => 'Loại nghỉ phép',
        'App\Models\ResignationRequest' => 'Đơn nghỉ việc',
        'App\Models\SalaryComponent' => 'Thành phần lương',
        'App\Models\HolidayAnnouncement' => 'Thông báo nghỉ lễ',
        'App\Models\RecruitmentOpening' => 'Đợt tuyển dụng',
        'App\Models\RecruitmentCandidate' => 'Ứng viên',
        'App\Models\RecruitmentInterview' => 'Phỏng vấn',
        'App\Models\RecruitmentOffer' => 'Offer',
        'App\Models\InterviewQuestionSet' => 'Bộ câu hỏi',
        'App\Models\InterviewScorecard' => 'Phiếu chấm phỏng vấn',
        'App\Models\Project' => 'Dự án',
        'App\Models\ProjectMember' => 'Thành viên dự án',
        'App\Models\ProjectTask' => 'Công việc dự án',
    ];

    // Nhãn tiếng Việt cho các trường hay gặp; trường khác hiện nguyên tên cột.
    public const FIELD_LABELS = [
        'full_name' => 'Họ tên', 'name' => 'Tên', 'title' => 'Tiêu đề', 'code' => 'Mã', 'status' => 'Trạng thái',
        'email' => 'Email', 'company_email' => 'Email công ty', 'personal_email' => 'Email cá nhân', 'phone' => 'Số điện thoại',
        'cccd' => 'CCCD', 'personal_tax_code' => 'Mã số thuế cá nhân', 'date_of_birth' => 'Ngày sinh', 'gender' => 'Giới tính',
        'department_id' => 'Phòng ban', 'position_id' => 'Chức vụ', 'manager_id' => 'Quản lý trực tiếp',
        'employment_status' => 'Tình trạng làm việc', 'hire_date' => 'Ngày vào làm', 'termination_date' => 'Ngày nghỉ việc',
        'contract_type' => 'Loại hợp đồng', 'contract_number' => 'Số hợp đồng', 'agreed_salary' => 'Lương thỏa thuận',
        'insurance_salary' => 'Lương đóng BHXH', 'start_date' => 'Ngày bắt đầu', 'end_date' => 'Ngày kết thúc',
        'due_date' => 'Hạn hoàn thành', 'bank_name' => 'Ngân hàng', 'account_number' => 'Số tài khoản',
        'account_holder' => 'Chủ tài khoản', 'is_primary' => 'Tài khoản chính', 'reason' => 'Lý do', 'note' => 'Ghi chú',
        'description' => 'Mô tả', 'assignee_employee_id' => 'Người được giao', 'lead_employee_id' => 'Lead',
        'priority' => 'Độ ưu tiên', 'points' => 'Độ khó', 'quality_score' => 'Điểm chất lượng',
        'approval_status' => 'Trạng thái duyệt', 'first_check_in_at' => 'Giờ vào', 'last_check_out_at' => 'Giờ ra',
        'total_days' => 'Số ngày', 'leave_type_id' => 'Loại phép', 'employee_id' => 'Nhân viên', 'user_id' => 'Tài khoản',
        'decision_note' => 'Ghi chú duyệt', 'review_note' => 'Nhận xét', 'last_working_date' => 'Ngày làm việc cuối',
        'approved_at' => 'Thời điểm duyệt', 'approved_by' => 'Người duyệt', 'approval_note' => 'Ghi chú duyệt',
        'attendance_date' => 'Ngày chấm công', 'work_shift_id' => 'Ca làm việc', 'late_minutes' => 'Số phút đi muộn',
        'early_leave_minutes' => 'Số phút về sớm', 'worked_minutes' => 'Số phút làm việc', 'overtime_minutes' => 'Số phút tăng ca',
        'deleted_at' => 'Thời điểm xóa', 'role' => 'Vai trò', 'confirmation_status' => 'Xác nhận tham gia',
        'scheduled_at' => 'Thời gian hẹn', 'submitted_at' => 'Thời điểm nộp', 'completed_at' => 'Thời điểm hoàn thành',
    ];

    public function list(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $page = $this->query($filters)
            ->with('user:id,user_name,email')
            ->orderByDesc('id')
            ->paginate($perPage);

        $page->getCollection()->transform(fn (AuditLog $log) => $this->present($log, false));

        return $page;
    }

    public function show(AuditLog $log): array
    {
        $log->loadMissing('user:id,user_name,email');

        // Các thay đổi khác trong CÙNG lần thao tác (vd tạo nhân viên kéo theo hợp đồng, ca làm).
        $related = $log->request_id
            ? AuditLog::where('request_id', $log->request_id)->whereKeyNot($log->id)->orderBy('id')->limit(20)->get()
                ->map(fn (AuditLog $other) => Arr::only($this->present($other, false), ['id', 'action', 'action_label', 'module', 'subject', 'summary']))
            : collect();

        return $this->present($log, true) + [
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'http_method' => $log->http_method,
            'url' => $log->url,
            'related' => $related->values()->all(),
        ];
    }

    // Danh sách cho bộ lọc: người đã có log + loại dữ liệu đã có log.
    public function filters(): array
    {
        $userIds = AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id');

        return [
            'users' => User::whereIn('id', $userIds)->orderBy('user_name')->get(['id', 'user_name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->user_name, 'email' => $u->email])->values()->all(),
            'modules' => AuditLog::query()->distinct()->pluck('auditable_type')
                ->map(fn (string $type) => ['value' => $type, 'label' => self::MODULES[$type] ?? class_basename($type)])
                ->sortBy('label')->values()->all(),
            'actions' => collect(self::ACTIONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
        ];
    }

    /**
     * Ghi sự kiện đăng nhập/đăng xuất (không đi qua Observer vì không phải thêm/sửa/xóa dữ liệu).
     * $user = tài khoản bị tác động (null khi email không tồn tại).
     */
    public function recordAuthEvent(string $action, ?User $user, ?string $email = null, array $extra = []): void
    {
        $request = request();

        AuditLog::create([
            'action' => $action,
            'auditable_type' => self::AUTH_TYPE,
            'auditable_id' => $user?->id,
            'old_data' => null,
            'new_data' => ['email' => $email ?? $user?->email] + $extra,
            'user_id' => $user?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'http_method' => $request->method(),
            'url' => $request->fullUrl(),
            'request_id' => AuditObserver::requestId(),
        ]);
    }

    /** Xóa log cũ hơn số tháng lưu trữ (config audit.retention_months). Trả về số dòng đã xóa. */
    public function prune(?Carbon $now = null): int
    {
        $cutoff = ($now ?? now())->copy()->subMonths(config('audit.retention_months'));
        $deleted = 0;

        // Xóa theo lô — tránh 1 câu DELETE khổng lồ khóa bảng lâu.
        do {
            $batch = DB::table('audit_logs')->where('created_at', '<', $cutoff)->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    private function query(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $id === 'system' ? $q->whereNull('user_id') : $q->where('user_id', $id))
            ->when($filters['module'] ?? null, fn ($q, $type) => $q->where('auditable_type', $type))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->whereIn('action', (array) $action))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('created_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('auditable_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($w) => $w
                ->where('old_data', 'like', "%{$search}%")
                ->orWhere('new_data', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")));
    }

    private function present(AuditLog $log, bool $withData): array
    {
        $old = $this->clean($log->old_data ?? []);
        $new = $this->clean($log->new_data ?? []);
        $changes = $log->action === 'updated' ? $this->diff($old, $new) : [];

        $result = [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => self::ACTIONS[$log->action] ?? $log->action,
            'module' => self::MODULES[$log->auditable_type] ?? class_basename($log->auditable_type),
            'module_type' => $log->auditable_type,
            'subject_id' => $log->auditable_id,
            'subject' => $this->subject($log, $old, $new),
            'summary' => $this->summary($log, $changes),
            'actor' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->user_name, 'email' => $log->user->email] : null,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at,
        ];

        if ($withData) {
            $result['changes'] = $changes;
            // Thêm/xóa: hiện toàn bộ dữ liệu của bản ghi tại thời điểm đó.
            $result['snapshot'] = $log->action === 'updated' ? [] : $this->labelled($log->action === 'deleted' ? $old : $new);
        }

        return $result;
    }

    // Bỏ trường ẩn, che trường nhạy cảm.
    private function clean(array $data): array
    {
        $data = Arr::except($data, config('audit.hidden_fields'));
        foreach (config('audit.masked_fields') as $field) {
            if (isset($data[$field]) && is_scalar($data[$field])) {
                $value = (string) $data[$field];
                $data[$field] = strlen($value) > 4 ? str_repeat('•', strlen($value) - 4).substr($value, -4) : $value;
            }
        }

        return $data;
    }

    // Chỉ các trường thật sự đổi (log "sửa" cũ lưu cả bản ghi cũ).
    private function diff(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $field => $after) {
            $before = $old[$field] ?? null;
            if ($this->normalize($before) === $this->normalize($after)) {
                continue;
            }
            $changes[] = ['field' => $field, 'label' => self::FIELD_LABELS[$field] ?? $field, 'before' => $before, 'after' => $after];
        }

        return $changes;
    }

    // So sánh bỏ qua khác biệt định dạng ngày ("2026-10-14T17:00:00.000000Z" vs "2026-10-15").
    private function normalize(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}:\d{2})?/', $value)) {
            try {
                return Carbon::parse($value)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return $value;
            }
        }

        return is_bool($value) ? (int) $value : (is_numeric($value) ? (string) (0 + $value) : $value);
    }

    private function labelled(array $data): array
    {
        return collect($data)
            ->map(fn ($value, $field) => ['field' => $field, 'label' => self::FIELD_LABELS[$field] ?? $field, 'value' => $value])
            ->values()->all();
    }

    // Tên dễ đọc của bản ghi: ưu tiên họ tên / tên / tiêu đề / mã.
    private function subject(AuditLog $log, array $old, array $new): string
    {
        if ($log->auditable_type === self::AUTH_TYPE) {
            return $new['email'] ?? '—';
        }

        $date = $new['attendance_date'] ?? $old['attendance_date'] ?? null;
        if (is_string($date) && $date !== '') {
            return 'Ngày '.Carbon::parse($date)->timezone(config('app.timezone'))->format('d/m/Y');
        }

        foreach (['full_name', 'name', 'title', 'code', 'contract_number'] as $key) {
            $value = $new[$key] ?? $old[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$log->auditable_id;
    }

    private function summary(AuditLog $log, array $changes): string
    {
        return match ($log->action) {
            'updated' => $changes === []
                ? 'Không có thay đổi hiển thị'
                : 'Đổi '.implode(', ', array_slice(array_column($changes, 'label'), 0, 3)).(count($changes) > 3 ? ' và '.(count($changes) - 3).' trường khác' : ''),
            'created' => 'Tạo mới',
            'deleted' => 'Xóa bản ghi',
            'login' => 'Đăng nhập thành công',
            'login_failed' => 'Sai mật khẩu',
            'login_locked' => 'Tài khoản bị khóa tạm do sai mật khẩu nhiều lần',
            'logout' => 'Đăng xuất',
            default => '',
        };
    }
}
