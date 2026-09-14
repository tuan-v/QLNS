<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollDetailComponent extends Model
{
    // component_name_snapshot/calculation_snapshot là bản SAO của SalaryComponent
    // tại đúng thời điểm chốt lương — cố tình KHÔNG đọc trực tiếp qua quan hệ
    // salaryComponent() để hiển thị phiếu lương. Lý do: nếu tháng sau HR sửa tên
    // hoặc công thức của SalaryComponent gốc, phiếu lương tháng cũ vẫn phải giữ
    // nguyên đúng những gì đã tính tại thời điểm đó, không được đổi theo.

    protected $fillable = [
        'payroll_detail_id',
        'salary_component_id',
        'component_name_snapshot',
        'calculation_snapshot',
        'quantity',
        'rate',
        'amount',
    ];

    protected $casts = [
        'calculation_snapshot' => 'array',
        'quantity' => 'decimal:2',
        'rate' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function payrollDetail()
    {
        return $this->belongsTo(PayrollDetail::class);
    }

    public function salaryComponent()
    {
        return $this->belongsTo(SalaryComponent::class);
    }
}
