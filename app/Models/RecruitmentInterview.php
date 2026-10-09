<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// Lịch phỏng vấn của 1 ứng viên. Mỗi ứng viên có tối đa 1 lịch 'scheduled' tại 1
// thời điểm (dời lịch = sửa chính lịch đó); ghi kết quả thì chuyển 'completed'.
class RecruitmentInterview extends Model
{
    use Auditable;
    use BroadcastsChanges;

    // Tạo/sửa/xóa tự báo realtime -> trang đang mở tự tải lại, không cần F5.
    protected array $realtimeShared = ['recruitment'];


    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_COMPLETED = 'completed';

    public const FORMAT_ONSITE = 'onsite';

    public const FORMAT_ONLINE = 'online';

    protected $fillable = [
        'recruitment_candidate_id', 'scheduled_at', 'format', 'location', 'province_code', 'commune_code', 'address_detail', 'interviewer_id',
        'status', 'result', 'result_note', 'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'province_code' => 'integer',
        'commune_code' => 'integer',
    ];

    public function candidate()
    {
        return $this->belongsTo(RecruitmentCandidate::class, 'recruitment_candidate_id');
    }

    public function interviewer()
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }
}
