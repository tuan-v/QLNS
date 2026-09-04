<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeBankAccount extends Model
{
    use Auditable;
    use SoftDeletes;
    protected $fillable = [
        'employee_id',
        'logo_bank',
        'bank_name',
        'bank_branch',
        'account_number',
        'account_holder',
        'is_primary',
    ];
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
