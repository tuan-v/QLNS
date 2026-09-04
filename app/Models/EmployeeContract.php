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
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'signed_at' => 'date',
        'terminated_at' => 'date',
        'agreed_salary' => 'decimal:2',
        'insurance_salary' => 'decimal:2',
    ];
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
