<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// Lịch phỏng vấn của 1 ứng viên. Mỗi ứng viên có tối đa 1 lịch 'scheduled' tại 1
// thời điểm (dời lịch = sửa chính lịch đó); ghi kết quả thì chuyển 'completed'.
// Phản hồi của ứng viên (HR ghi nhận, báo Admin): confirmation_status pending ->
// confirmed | reschedule_requested (giữ ứng viên, HR dời lịch -> về pending) |
// withdrawn (lịch 'cancelled', ứng viên "Từ chối phỏng vấn", trả lại suất).
class RecruitmentInterview extends Model
{
    use Auditable;
    use BroadcastsChanges;

    // Tạo/sửa/xóa tự báo realtime -> trang đang mở tự tải lại, không cần F5.
    protected array $realtimeShared = ['recruitment'];


    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const CONFIRMATION_PENDING = 'pending';

    public const CONFIRMATION_CONFIRMED = 'confirmed';

    public const CONFIRMATION_RESCHEDULE = 'reschedule_requested';

    public const CONFIRMATION_WITHDRAWN = 'withdrawn';

    public const CONFIRMATION_STATUSES = [
        self::CONFIRMATION_PENDING, self::CONFIRMATION_CONFIRMED, self::CONFIRMATION_RESCHEDULE, self::CONFIRMATION_WITHDRAWN,
    ];

    public const FORMAT_ONSITE = 'onsite';

    public const FORMAT_ONLINE = 'online';

    protected $fillable = [
        'recruitment_candidate_id', 'scheduled_at', 'format', 'location', 'province_code', 'commune_code', 'address_detail', 'interviewer_id',
        'status', 'result', 'result_note', 'created_by',
        'confirmation_status', 'confirmation_note', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'province_code' => 'integer',
        'commune_code' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    public function scorecard()
    {
        return $this->hasOne(InterviewScorecard::class);
    }

    public function candidate()
    {
        return $this->belongsTo(RecruitmentCandidate::class, 'recruitment_candidate_id');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function interviewer()
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }
}
