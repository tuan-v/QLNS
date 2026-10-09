<?php

namespace App\Models;

use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Checklist Onboarding/Offboarding của 1 nhân viên — các việc được CHÉP từ mẫu lúc tạo.
class EmployeeChecklist extends Model
{
    use BroadcastsChanges;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected array $realtimeShared = ['onboarding'];

    protected array $realtimeOwn = ['onboarding'];

    protected $fillable = [
        'employee_id', 'type', 'checklist_template_id', 'template_name', 'reference_date',
        'status', 'resignation_request_id', 'completed_at', 'cancelled_at', 'created_by',
    ];

    protected $casts = [
        'reference_date' => 'date',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resignationRequest(): BelongsTo
    {
        return $this->belongsTo(ResignationRequest::class);
    }
}
