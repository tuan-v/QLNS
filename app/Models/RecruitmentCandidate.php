<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    // Ứng viên báo không tham gia phỏng vấn nữa (HR ghi nhận) — trả lại suất.
    public const STATUS_INTERVIEW_DECLINED = 'interview_declined';

    // GIAI ĐOẠN hiển thị (2026-10-13, người dùng duyệt): 1 trạng thái thống nhất cho người dùng,
    // suy ra từ mã trạng thái (giữ nguyên trong DB) + offer gần nhất. Kết cục gom về
    // "Không đạt" / "Đã rút lui" kèm lý do. Xem stageFor().
    public const STAGES = [
        'screening' => 'Chờ duyệt CV',
        'awaiting_schedule' => 'Chờ hẹn phỏng vấn',
        'invited' => 'Đã hẹn – chờ xác nhận',
        'awaiting_interview' => 'Chờ phỏng vấn',
        'interviewing' => 'Đang phỏng vấn',
        'passed' => 'Đạt phỏng vấn',
        'offering' => 'Đang đề nghị (offer)',
        'awaiting_onboard' => 'Chờ nhận việc',
        'hired' => 'Đã tuyển',
        'not_passed' => 'Không đạt',
        'withdrawn' => 'Đã rút lui',
    ];

    /**
     * Buổi PV đang hẹn: chưa trả lời / xin đổi lịch -> "Đã hẹn – chờ xác nhận"; đã đồng ý ->
     * "Chờ phỏng vấn", tới giờ hẹn TỰ thành "Đang phỏng vấn" (tính theo giờ lúc đọc, không cần job).
     *
     * @return array{key: string, label: string, reason: ?string}
     */
    public static function stageFor(string $status, ?string $latestOfferStatus, ?string $interviewConfirmation = null, ?CarbonInterface $interviewAt = null): array
    {
        [$key, $reason] = match ($status) {
            self::STATUS_PENDING => ['screening', null],
            self::STATUS_APPROVED => ['awaiting_schedule', null],
            self::STATUS_INTERVIEW_SCHEDULED => match ($interviewConfirmation) {
                RecruitmentInterview::CONFIRMATION_CONFIRMED => $interviewAt !== null && $interviewAt->lte(Carbon::now())
                    ? ['interviewing', null]
                    : ['awaiting_interview', null],
                RecruitmentInterview::CONFIRMATION_RESCHEDULE => ['invited', 'Ứng viên xin đổi lịch'],
                default => ['invited', null],
            },
            self::STATUS_PASSED => match ($latestOfferStatus) {
                RecruitmentOffer::STATUS_PENDING_APPROVAL, RecruitmentOffer::STATUS_SENT => ['offering', null],
                RecruitmentOffer::STATUS_ACCEPTED => ['awaiting_onboard', null],
                default => ['passed', null], // chưa có offer / offer không được duyệt / đã hủy
            },
            self::STATUS_HIRED => ['hired', null],
            self::STATUS_REJECTED => ['not_passed', 'Loại ở vòng CV'],
            self::STATUS_FAILED => ['not_passed', 'Trượt phỏng vấn'],
            self::STATUS_INTERVIEW_DECLINED => ['withdrawn', 'Không tham gia phỏng vấn'],
            self::STATUS_OFFER_DECLINED => ['withdrawn', $latestOfferStatus === RecruitmentOffer::STATUS_EXPIRED ? 'Không phản hồi offer' : 'Từ chối offer'],
            default => [$status, null],
        };

        return ['key' => $key, 'label' => self::STAGES[$key] ?? $status, 'reason' => $reason];
    }

    /** Giai đoạn của ứng viên này (dùng offer gần nhất / buổi PV đã nạp sẵn nếu có). */
    public function stage(): array
    {
        $offer = $this->relationLoaded('latestOffer') ? $this->latestOffer : $this->latestOffer()->first();
        $interview = null;
        if ($this->status === self::STATUS_INTERVIEW_SCHEDULED) {
            $interview = $this->relationLoaded('interviews')
                ? $this->interviews->firstWhere('status', RecruitmentInterview::STATUS_SCHEDULED)
                : $this->interviews()->where('status', RecruitmentInterview::STATUS_SCHEDULED)->first();
        }

        return self::stageFor($this->status, $offer?->status, $interview?->confirmation_status, $interview?->scheduled_at);
    }

    // Nguồn ứng viên (kênh nhận CV) — để báo cáo kênh nào hiệu quả.
    public const SOURCES = [
        'topcv' => 'TopCV',
        'vietnamworks' => 'VietnamWorks',
        'itviec' => 'ITviec',
        'linkedin' => 'LinkedIn',
        'facebook' => 'Facebook / mạng xã hội',
        'website' => 'Website công ty',
        'referral' => 'Người giới thiệu',
        'job_fair' => 'Ngày hội việc làm / trường học',
        'other' => 'Khác',
    ];

    // CV đang "chiếm suất" của đợt tuyển — tính vào giới hạn headcount. Bị từ chối
    // hoặc trượt phỏng vấn thì trả lại suất cho CV khác.
    public const COUNTED_STATUSES = [
        self::STATUS_APPROVED, self::STATUS_INTERVIEW_SCHEDULED, self::STATUS_PASSED, self::STATUS_HIRED,
    ];

    protected $fillable = [
        'recruitment_opening_id', 'full_name', 'email', 'phone', 'applied_position_id', 'desired_contract_type', 'source', 'source_note', 'cv_file', 'cv_original_name', 'note',
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

    // Vị trí ứng tuyển (chức vụ có sẵn) — dùng cho đợt "tuyển dụng chung".
    public function appliedPosition()
    {
        return $this->belongsTo(Position::class, 'applied_position_id');
    }

    // Bộ câu hỏi RIÊNG của ứng viên (Admin chọn), kèm tỉ trọng %.
    public function questionSets()
    {
        return $this->belongsToMany(InterviewQuestionSet::class, 'recruitment_candidate_question_sets')
            ->withPivot('weight', 'sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Bộ câu hỏi dùng khi chấm ứng viên này + nguồn:
     *  1. 'candidate' — Admin chọn riêng cho ứng viên;
     *  2. đợt tuyển CHUNG: 'position' — mặc định của vị trí ứng tuyển, không có thì 'opening';
     *  3. đợt theo chức vụ: 'opening' — bộ của đợt tuyển.
     * Dùng quan hệ đã nạp sẵn nếu có (tránh N+1 ở bảng ứng viên / lịch phỏng vấn).
     *
     * @return array{0: \Illuminate\Support\Collection, 1: ?string}
     */
    public function effectiveQuestionSets(): array
    {
        $own = $this->relationLoaded('questionSets') ? $this->questionSets : $this->questionSets()->get();
        if ($own->isNotEmpty()) {
            return [$own, 'candidate'];
        }

        $opening = $this->relationLoaded('opening') ? $this->opening : $this->opening()->first();
        $openingSets = $opening ? ($opening->relationLoaded('questionSets') ? $opening->questionSets : $opening->questionSets()->get()) : collect();

        if ($opening?->isGeneral() && $this->applied_position_id) {
            $position = $this->relationLoaded('appliedPosition') ? $this->appliedPosition : $this->appliedPosition()->first();
            $positionSets = $position ? ($position->relationLoaded('questionSets') ? $position->questionSets : $position->questionSets()->get()) : collect();
            if ($positionSets->isNotEmpty()) {
                return [$positionSets, 'position'];
            }
        }

        return $openingSets->isNotEmpty() ? [$openingSets, 'opening'] : [collect(), null];
    }
}
