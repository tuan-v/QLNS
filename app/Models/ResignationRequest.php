<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

class ResignationRequest extends Model
{
    use Auditable;
    use BroadcastsChanges;

    protected array $realtimeShared = ['resignations'];

    protected array $realtimeOwn = ['resignations'];


    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    // Báo trước đủ ngày theo luật -> chỉ là thông báo, không cần duyệt.
    public const STATUS_NOTIFIED = 'notified';

    protected $fillable = [
        'employee_id',
        'last_working_date',
        'reason',
        'notice_days_required',
        'notice_days_given',
        'requires_approval',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'applied_at',
    ];

    // 'date:Y-m-d' (không phải 'date' trần) — cùng lý do ở Employee::$casts:
    // tránh JSON đổi sang UTC làm lệch về ngày hôm trước.
    protected $casts = [
        'last_working_date' => 'date:Y-m-d',
        'decided_at' => 'datetime',
        'applied_at' => 'datetime',
        'requires_approval' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
