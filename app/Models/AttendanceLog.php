<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use Auditable;

    // Migration chỉ có created_at (useCurrent()), không có updated_at — tắt
    // để Eloquent không cố ghi vào cột không tồn tại. Bản ghi nhật ký sự
    // kiện, không sửa lại sau khi tạo nên không cần updated_at lẫn SoftDeletes
    // (xem migration add_soft_deletes_to_attendances_table.php).
    public const UPDATED_AT = null;

    protected $fillable = [
        'employee_id',
        'attendance_id',
        'attendance_location_id',
        'event_type',
        'occurred_at',
        'method',
        'latitude',
        'longitude',
        'accuracy_meters',
        'ip_address',
        'qr_reference',
        'raw_data',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'raw_data' => 'array',
        // Cùng lý do ép float ở WorkShift::$casts.
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy_meters' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    public function attendanceLocation()
    {
        return $this->belongsTo(AttendanceLocation::class);
    }
}
