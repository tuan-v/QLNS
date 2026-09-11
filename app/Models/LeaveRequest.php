<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use Auditable;
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'start_session',
        'end_session',
        'start_time',
        'end_time',
        'total_days',
        'reason',
        'evidence_file_path',
        'status',
        'submitted_at',
        'cancelled_at',
    ];

    protected $casts = [
        'from_date' => 'date:Y-m-d',
        'to_date' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        // Cột decimal MySQL mặc định trả về CHUỖI thô ("1.00") nếu không ép
        // kiểu — hiện ra Frontend xấu (Số ngày: "1.00" thay vì "1"). Ép float
        // ở đây thay vì sửa từng chỗ hiển thị bên Frontend, để MỌI nơi trả
        // về LeaveRequest (list, tạo đơn, duyệt...) đều tự động sạch.
        'total_days' => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
    public function approvals()
    {
        return $this->hasMany(LeaveApproval::class);
    }
}
