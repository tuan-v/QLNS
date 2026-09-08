<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeContract extends Model
{
    use Auditable;
    use SoftDeletes;
    protected $fillable = [
        'employee_id',
        'contract_number',
        'contract_type',
        'start_date',
        'end_date',
        'signed_at',
        'agreed_salary',
        'insurance_salary',
        'status',
        'terminated_at',
        'contract_file_path',
    ];
    // 'date:Y-m-d' — tránh Carbon quy đổi sang UTC khi ra JSON, xem ghi chú ở
    // Employee.php (cùng lỗi lệch ngày, chung nguyên nhân múi giờ Asia/Ho_Chi_Minh).
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'signed_at' => 'date:Y-m-d',
        'terminated_at' => 'date:Y-m-d',
        'agreed_salary' => 'decimal:2',
        'insurance_salary' => 'decimal:2',
    ];
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
