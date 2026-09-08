<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class EmployeeTransfer extends Model
{
    use Auditable;

    // Lịch sử luân chuyển là bản ghi sự kiện đã xảy ra — không xóa mềm vì
    // không có khái niệm "xóa 1 lần luân chuyển trong quá khứ", giống hợp
    // đồng lao động (chỉ Tạo, không Sửa/Xóa).
    protected $fillable = [
        'employee_id',
        'from_department_id',
        'to_department_id',
        'new_manager_id',
        'old_position_id',
        'new_position_id',
        'effective_date',
        'reason',
        'decision_file',
        'approved_at',
        'approved_by',
    ];

    // 'effective_date' dùng 'date:Y-m-d' — tránh Carbon quy đổi sang UTC khi ra
    // JSON, xem ghi chú ở Employee.php. 'approved_at' giữ 'datetime' vì đây là
    // mốc thời điểm thật (có giờ, có ý nghĩa múi giờ), khác effective_date chỉ
    // là 1 ngày lịch không gắn giờ nào.
    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function fromDepartment()
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function toDepartment()
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function newManager()
    {
        return $this->belongsTo(Employee::class, 'new_manager_id');
    }

    public function oldPosition()
    {
        return $this->belongsTo(Position::class, 'old_position_id');
    }

    public function newPosition()
    {
        return $this->belongsTo(Position::class, 'new_position_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
