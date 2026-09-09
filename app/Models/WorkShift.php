<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkShift extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'standard_work_minutes',
        'late_grace_minutes',
        'early_leave_grace_minutes',
        'work_coefficient',
        'is_active',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
