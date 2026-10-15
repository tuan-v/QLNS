<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Dự án (2026-10-14) — xem ProjectService. Lead là quyền THEO DỰ ÁN (project_members.role),
// không phải vai trò hệ thống: A là Lead dự án X nhưng chỉ là thành viên dự án Y.
class Project extends Model
{
    use Auditable;
    use BroadcastsChanges;
    use SoftDeletes;

    protected array $realtimeShared = ['projects'];

    public const STATUS_PLANNING = 'planning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNING, self::STATUS_ACTIVE, self::STATUS_ON_HOLD, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    // Dự án đã đóng: không giao việc / thêm người nữa (vẫn xem được).
    public const CLOSED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    protected $fillable = [
        'code', 'name', 'description', 'department_id', 'lead_employee_id',
        'start_date', 'end_date', 'status', 'created_by',
        'start_reminded_at', 'end_soon_notified_at', 'overdue_notified_at',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'start_reminded_at' => 'datetime',
        'end_soon_notified_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
    ];

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function lead()
    {
        return $this->belongsTo(Employee::class, 'lead_employee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function tasks()
    {
        return $this->hasMany(ProjectTask::class);
    }
}
