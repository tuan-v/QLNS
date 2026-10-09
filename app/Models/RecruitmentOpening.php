<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Đợt tuyển dụng cho 1 chức vụ. headcount = số người cần tuyển = giới hạn số CV
// được duyệt (đủ thì tự chuyển "full", ngưng nhận CV) — RecruitmentService::syncOpeningStatus().
class RecruitmentOpening extends Model
{
    use Auditable;
    use BroadcastsChanges;

    use SoftDeletes;

    // Tạo/sửa/xóa tự báo realtime -> trang đang mở tự tải lại, không cần F5.
    protected array $realtimeShared = ['recruitment'];

    public const STATUS_OPEN = 'open';     // Đang nhận CV

    public const STATUS_FULL = 'full';     // Đủ CV đã duyệt

    public const STATUS_CLOSED = 'closed'; // HR đóng tay

    protected $fillable = [
        'title', 'department_id', 'position_id', 'contract_type', 'headcount',
        'deadline', 'description', 'status', 'created_by',
    ];

    protected $casts = [
        'headcount' => 'integer',
        'deadline' => 'date:Y-m-d',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function candidates()
    {
        return $this->hasMany(RecruitmentCandidate::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Đã hết hạn nộp CV (deadline là ngày cuối còn nhận).
    public function isPastDeadline(): bool
    {
        return $this->deadline !== null && $this->deadline->lt(now()->startOfDay());
    }
}
