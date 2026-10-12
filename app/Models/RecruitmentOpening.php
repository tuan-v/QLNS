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

    // Loại hợp đồng của đợt tuyển: 1 loại cố định, hoặc "tất cả" = để HR chọn loại cụ
    // thể cho từng ứng viên lúc soạn offer (RecruitmentOfferService).
    public const CONTRACT_TYPE_ANY = 'tat_ca';

    public const CONTRACT_TYPES = ['thu_viec', 'chinh_thuc', 'thuc_tap', self::CONTRACT_TYPE_ANY];

    protected $fillable = [
        'title', 'department_id', 'position_id', 'interview_question_set_id', 'contract_type', 'headcount',
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

    // Bộ câu hỏi phỏng vấn Admin gắn cho đợt tuyển (InterviewScorecardService).
    public function questionSet()
    {
        return $this->belongsTo(InterviewQuestionSet::class, 'interview_question_set_id');
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
