<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Ứng viên + CV của 1 đợt tuyển. Luồng trạng thái (RecruitmentService):
// pending -> approved | rejected (Admin) ; approved -> interview_scheduled (HR hẹn)
// ; interview_scheduled -> passed | failed (HR ghi kết quả) ; passed -> offer (RecruitmentOffer:
// HR soạn, Admin duyệt, ứng viên trả lời) -> hired (tạo nhân viên, cần offer đã chấp nhận)
// | offer_declined (ứng viên từ chối/không trả lời offer — trả lại suất).
class RecruitmentCandidate extends Model
{
    use Auditable;
    use BroadcastsChanges;

    use SoftDeletes;

    // Tạo/sửa/xóa tự báo realtime -> trang đang mở tự tải lại, không cần F5.
    protected array $realtimeShared = ['recruitment'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_INTERVIEW_SCHEDULED = 'interview_scheduled';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_HIRED = 'hired';

    public const STATUS_OFFER_DECLINED = 'offer_declined';

    // CV đang "chiếm suất" của đợt tuyển — tính vào giới hạn headcount. Bị từ chối
    // hoặc trượt phỏng vấn thì trả lại suất cho CV khác.
    public const COUNTED_STATUSES = [
        self::STATUS_APPROVED, self::STATUS_INTERVIEW_SCHEDULED, self::STATUS_PASSED, self::STATUS_HIRED,
    ];

    protected $fillable = [
        'recruitment_opening_id', 'full_name', 'email', 'phone', 'cv_file', 'cv_original_name', 'note',
        'status', 'submitted_by', 'reviewed_by', 'reviewed_at', 'review_note', 'hired_employee_id',
    ];

    protected $hidden = ['cv_file'];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function opening()
    {
        return $this->belongsTo(RecruitmentOpening::class, 'recruitment_opening_id');
    }

    public function offers()
    {
        return $this->hasMany(RecruitmentOffer::class)->latest('id');
    }

    // Offer gần nhất (hiển thị trên bảng ứng viên + điền sẵn form Nhận việc).
    public function latestOffer()
    {
        return $this->hasOne(RecruitmentOffer::class)->latestOfMany();
    }

    public function interviews()
    {
        return $this->hasMany(RecruitmentInterview::class)->latest('scheduled_at');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function hiredEmployee()
    {
        return $this->belongsTo(Employee::class, 'hired_employee_id');
    }
}
