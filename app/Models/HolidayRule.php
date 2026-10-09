<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BroadcastsChanges;

// Quy tắc ngày nghỉ lặp hằng năm (dương lịch hoặc âm lịch) — xem HolidayService::generateForYear().
class HolidayRule extends Model
{
    use BroadcastsChanges;

    // Tạo/sửa/xóa tự báo realtime -> trang đang mở tự tải lại, không cần F5.
    protected array $realtimeShared = ['holidays'];

    public const CALENDAR_SOLAR = 'solar';

    public const CALENDAR_LUNAR = 'lunar';

    protected $fillable = [
        'name', 'calendar', 'month', 'day', 'offset_days', 'auto_adjacent', 'duration_days',
        'compensate_weekend', 'is_paid', 'intern_paid', 'notify_days_before', 'confirm_remind_days_before', 'is_active', 'effective_from', 'description',
    ];

    protected $casts = [
        'month' => 'integer',
        'day' => 'integer',
        'offset_days' => 'integer',
        'duration_days' => 'integer',
        'notify_days_before' => 'integer',
        'confirm_remind_days_before' => 'integer',
        'auto_adjacent' => 'boolean',
        'compensate_weekend' => 'boolean',
        // Thực tập sinh được hưởng lương ngày nghỉ của quy tắc này (PayrollService).
        'intern_paid' => 'boolean',
        'is_paid' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'effective_from' => 'date:Y-m-d',
    ];

    public function holidays()
    {
        return $this->hasMany(Holiday::class);
    }
}
