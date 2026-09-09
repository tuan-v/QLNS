<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeShiftAssignment extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'work_shift_id',
        'effective_from',
        'effective_to',
        'work_days',
        'status',
    ];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        // Cột JSON — ép về mảng PHP, tự encode/decode 2 chiều qua Eloquent.
        'work_days' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workShift()
    {
        return $this->belongsTo(WorkShift::class);
    }
}
