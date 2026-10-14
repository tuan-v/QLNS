<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Công việc trong dự án. Luồng (ProjectTaskService):
//   todo -> in_progress (người được giao bắt đầu) -> in_review (nộp, chờ Lead duyệt)
//   -> done (Lead duyệt + chấm chất lượng 1-5) | quay lại in_progress (Lead trả lại kèm lý do).
//   Lead/quản lý hủy được việc chưa xong (cancelled — không tính vào KPI).
class ProjectTask extends Model
{
    use Auditable;
    use BroadcastsChanges;
    use SoftDeletes;

    protected array $realtimeShared = ['projects'];

    // Trang "Công việc của tôi" của người được giao (kênh user-sync).
    protected array $realtimeOwn = ['tasks'];

    public const STATUS_TODO = 'todo';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_TODO, self::STATUS_IN_PROGRESS, self::STATUS_IN_REVIEW, self::STATUS_DONE, self::STATUS_CANCELLED,
    ];

    // Việc còn "mở" (chưa xong, chưa hủy) — tính quá hạn, chặn rời dự án.
    public const OPEN_STATUSES = [self::STATUS_TODO, self::STATUS_IN_PROGRESS, self::STATUS_IN_REVIEW];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    // Thang điểm độ khó kiểu Fibonacci (quen thuộc với Scrum) — trọng số KPI.
    public const POINTS = [1, 2, 3, 5, 8];

    protected $fillable = [
        'project_id', 'title', 'description', 'assignee_employee_id', 'priority', 'points',
        'start_date', 'due_date', 'status', 'started_at', 'submitted_at', 'completed_at', 'submission_note', 'quality_score',
        'review_note', 'return_count', 'reviewed_by', 'due_soon_notified_at', 'overdue_notified_at', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'due_soon_notified_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
        'points' => 'integer',
        'quality_score' => 'integer',
        'return_count' => 'integer',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    // Quá hạn = còn mở mà đã qua hết ngày hạn chót.
    public function isOverdue(?Carbon $today = null): bool
    {
        $today ??= Carbon::today();

        return $this->isOpen() && $this->due_date->lt($today);
    }

    // Đúng hạn = lần nộp được duyệt nằm trong ngày hạn chót (xét theo NGÀY nộp, không
    // phải ngày Lead duyệt — Lead duyệt chậm không làm nhân viên bị tính trễ).
    public function finishedOnTime(): ?bool
    {
        if ($this->status !== self::STATUS_DONE || ! $this->submitted_at) {
            return null;
        }

        return $this->submitted_at->toDateString() <= $this->due_date->toDateString();
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function realtimeEmployeeIds(): array
    {
        $ids = array_filter([$this->assignee_employee_id, $this->getOriginal('assignee_employee_id')]);

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
