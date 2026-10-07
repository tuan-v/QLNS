<?php

namespace App\Models;

use Carbon\Carbon;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Một ngày nghỉ cụ thể. Các ngày cùng một dịp (Tết 5 ngày + nghỉ bù + hoán đổi)
// chung group_code.
class Holiday extends Model
{
    use SoftDeletes;
    use BroadcastsChanges;

    // Tạo/sửa/xóa tự báo realtime -> trang đang mở tự tải lại, không cần F5.
    protected array $realtimeShared = ['holidays'];


    public const SOURCE_RULE = 'rule';

    public const SOURCE_COMPENSATORY = 'compensatory';

    public const SOURCE_MANUAL = 'manual';

    // HR thêm một ngày vào dịp sinh từ quy tắc (theo thông báo chính thức).
    public const SOURCE_ADJUSTED = 'adjusted';

    // Nghỉ hoán đổi: nghỉ ngày thường này, đi làm bù vào makeup_date (Thứ 7/CN).
    public const SOURCE_SWAP = 'swap';

    protected $fillable = [
        'holiday_rule_id', 'group_code', 'source', 'holiday_date', 'makeup_date', 'name',
        'is_paid', 'intern_paid', 'work_coefficient', 'note', 'notified_at',
        'confirmed_at', 'confirmed_by', 'confirm_reminded_at',
    ];

    protected $casts = [
        'holiday_date' => 'date:Y-m-d',
        'makeup_date' => 'date:Y-m-d',
        'is_paid' => 'boolean',
        'intern_paid' => 'boolean',
        'work_coefficient' => 'float',
        'notified_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'confirm_reminded_at' => 'datetime',
    ];

    public function rule()
    {
        return $this->belongsTo(HolidayRule::class, 'holiday_rule_id');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // "Thứ" dùng để xét ca làm việc của một ngày: ngày đi làm bù (Thứ 7/CN của một
    // lần hoán đổi) được tính như chính ngày thường đã được nghỉ thay — nhân viên
    // chấm công được như hôm đó. Ngày khác trả về thứ thật của ngày.
    public static function effectiveWeekdayIso(Carbon|string $date): int
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $swappedOff = self::where('makeup_date', $date->toDateString())->value('holiday_date');

        return $swappedOff ? Carbon::parse($swappedOff)->dayOfWeekIso : $date->dayOfWeekIso;
    }
}
