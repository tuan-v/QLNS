<?php

namespace App\Models;

use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeChecklistItem extends Model
{
    use BroadcastsChanges;

    protected array $realtimeShared = ['onboarding'];

    protected array $realtimeOwn = ['onboarding'];

    protected $fillable = [
        'employee_checklist_id', 'title', 'description', 'responsible', 'due_date',
        'is_required', 'auto_key', 'sort_order', 'completed_at', 'completed_by', 'note',
    ];

    protected $casts = [
        'due_date' => 'date',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(EmployeeChecklist::class, 'employee_checklist_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @return array<int, int> */
    protected function realtimeEmployeeIds(): array
    {
        $employeeId = EmployeeChecklist::whereKey($this->employee_checklist_id)->value('employee_id');

        return $employeeId ? [(int) $employeeId] : [];
    }
}
