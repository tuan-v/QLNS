<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// Văn bản thông báo nghỉ lễ của một dịp (group_code) — xem HolidayAnnouncementService.
class HolidayAnnouncement extends Model
{
    use Auditable;
    use BroadcastsChanges;

    public const STATUS_DRAFT = 'draft';

    // Đã chốt nội dung, chờ người đại diện ký + đóng dấu bản giấy.
    public const STATUS_PENDING_SIGNATURE = 'pending_signature';

    public const STATUS_PUBLISHED = 'published';

    // Nhóm hưởng thưởng, khớp loại hợp đồng hiệu lực vào ngày đầu kỳ nghỉ.
    public const CATEGORY_CONTRACT_TYPES = [
        'official' => 'chinh_thuc',
        'probation' => 'thu_viec',
        'intern' => 'thuc_tap',
    ];

    protected array $realtimeShared = ['holidays'];

    protected $fillable = [
        'group_code',
        'document_number',
        'issued_date',
        'content',
        'has_bonus',
        'bonus_rows',
        'bonus_payroll_month',
        'bonus_payroll_year',
        'status',
        'schedule_snapshot',
        'signed_file',
        'signed_uploaded_at',
        'signed_uploaded_by',
        'published_at',
        'published_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'issued_date' => 'date:Y-m-d',
        'content' => 'array',
        'has_bonus' => 'boolean',
        'bonus_rows' => 'array',
        'bonus_payroll_month' => 'integer',
        'bonus_payroll_year' => 'integer',
        'schedule_snapshot' => 'array',
        'signed_uploaded_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function signedUploader()
    {
        return $this->belongsTo(User::class, 'signed_uploaded_by');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
