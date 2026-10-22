<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Kết quả KPI ĐÃ CHỐT của 1 nhân viên trong 1 dự án, 1 tháng (xem KpiService::lock()).
class KpiMonthlyResult extends Model
{
    protected $fillable = [
        'year', 'month', 'employee_id', 'project_id', 'task_count', 'done_count', 'points_due', 'points_done',
        'points_on_time', 'quality_points', 'return_count', 'tasks',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'employee_id' => 'integer',
        'project_id' => 'integer',
        'task_count' => 'integer',
        'done_count' => 'integer',
        'points_due' => 'integer',
        'points_done' => 'integer',
        'points_on_time' => 'integer',
        'quality_points' => 'integer',
        'return_count' => 'integer',
        'tasks' => 'array',
    ];
}
