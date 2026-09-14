<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryComponent extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'component_type',
        'calculation_rule',
        'is_taxable',
        'is_active',
    ];

    protected $casts = [
        'calculation_rule' => 'array',
        'is_taxable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function payrollDetailComponents()
    {
        return $this->hasMany(PayrollDetailComponent::class);
    }
}
