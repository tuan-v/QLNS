<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Danh mục khoản lương. component_type: allowance (phụ cấp) / subsidy (trợ cấp) do HR khai ở trang
// "Phụ cấp & trợ cấp" (AllowanceService); bonus (thưởng lễ, tiền phép) do hệ thống tạo sẵn.
class SalaryComponent extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'component_type',
        'calculation_method',
        'calculation_rule',
        'is_taxable',
        'tax_free_amount',
        'in_insurance_salary',
        'applies_to_all',
        'suggested_amounts',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'calculation_rule' => 'array',
        'suggested_amounts' => 'array',
        'is_taxable' => 'boolean',
        'tax_free_amount' => 'float',
        'in_insurance_salary' => 'boolean',
        'applies_to_all' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function payrollDetailComponents()
    {
        return $this->hasMany(PayrollDetailComponent::class);
    }

    public function employeeAllowances()
    {
        return $this->hasMany(EmployeeAllowance::class);
    }
}
