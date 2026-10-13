<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Đợt tuyển dụng cho 1 chức vụ. headcount = số người cần tuyển = giới hạn số CV
// được duyệt (đủ thì tự chuyển "full", ngưng nhận CV) — RecruitmentService::syncOpeningStatus().
// "Tuyển dụng chung" (2026-10-13): không phòng ban/chức vụ (department_id null), headcount
// null = không giới hạn; mỗi ứng viên ghi vị trí ứng tuyển, phòng ban/chức vụ chốt trong offer.
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

    // Các bộ câu hỏi phỏng vấn của đợt tuyển, mỗi bộ 1 tỉ trọng % (tổng 100) — Admin gắn,
    // mặc định chép từ chức vụ khi tạo đợt (InterviewScorecardService / RecruitmentService).
    public function questionSets()
    {
        return $this->belongsToMany(InterviewQuestionSet::class, 'recruitment_opening_question_sets')
            ->withPivot('weight', 'sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
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

    public function isGeneral(): bool
    {
        return $this->department_id === null;
    }

    // Đủ suất? Không giới hạn (headcount null) thì không bao giờ đủ.
    public function isFullWith(int $countedCandidates): bool
    {
        return $this->headcount !== null && $countedCandidates >= $this->headcount;
    }

    // Đã hết hạn nộp CV (deadline là ngày cuối còn nhận).
    public function isPastDeadline(): bool
    {
        return $this->deadline !== null && $this->deadline->lt(now()->startOfDay());
    }
}
