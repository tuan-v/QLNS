<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Đánh dấu tháng đã chốt KPI (kể cả tháng không có việc nào) — từ lúc này KPI tháng đó đọc từ
// kpi_monthly_results thay vì tính lại từ project_tasks.
class KpiMonthLock extends Model
{
    protected $fillable = ['year', 'month', 'locked_at'];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'locked_at' => 'datetime',
    ];
}
