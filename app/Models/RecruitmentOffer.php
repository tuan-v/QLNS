<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// Thư mời nhận việc của 1 ứng viên — xem RecruitmentOfferService.
// pending_approval -> sent (Admin duyệt, gửi email) | rejected (Admin không duyệt)
// sent -> accepted | declined (ứng viên trả lời) | expired (quá hạn trả lời)
// pending_approval | sent -> withdrawn (HR rút lại)
class RecruitmentOffer extends Model
{
    use Auditable;
    use BroadcastsChanges;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_WITHDRAWN = 'withdrawn';

    // Offer "đang mở" — mỗi ứng viên tối đa 1 offer ở các trạng thái này.
    public const OPEN_STATUSES = [self::STATUS_PENDING_APPROVAL, self::STATUS_SENT, self::STATUS_ACCEPTED];

    protected array $realtimeShared = ['recruitment'];

    protected $fillable = [
        'recruitment_candidate_id', 'department_id', 'position_id', 'contract_type', 'salary', 'start_date', 'response_deadline', 'message', 'contact_name', 'contact_phone', 'contact_email',
        'status', 'created_by', 'reviewed_by', 'reviewed_at', 'review_note', 'token_hash', 'sent_at',
        'responded_at', 'response_note', 'response_recorded_by',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'salary' => 'decimal:2',
        'start_date' => 'date',
        'response_deadline' => 'date',
        'reviewed_at' => 'datetime',
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(RecruitmentCandidate::class, 'recruitment_candidate_id');
    }

    // Phòng ban / chức vụ chốt cho ứng viên (điền sẵn theo đợt tuyển hoặc vị trí ứng tuyển).
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // HR ghi nhận phản hồi của ứng viên.
    public function responseRecorder()
    {
        return $this->belongsTo(User::class, 'response_recorded_by');
    }

    // Hết hạn trả lời: qua hết ngày response_deadline.
    public function isPastDeadline(): bool
    {
        return $this->response_deadline->lt(today());
    }
}
