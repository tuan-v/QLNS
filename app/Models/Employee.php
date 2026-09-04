<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Auditable;
    use SoftDeletes;
    protected $fillable = [
        'user_id',
        'department_id',
        'position_id',
        'manager_id',
        'code',
        'full_name',
        'date_of_birth',
        'gender',
        'phone',
        'company_email',
        'personal_email',
        'cccd',
        'addresses',
        'personal_tax_code',
        'avatar',
        'hire_date',
        'probation_end_date',
        'termination_date',
        'employment_status',
    ];
    protected $casts = [
        'date_of_birth' => 'date',
        'hire_date' => 'date',
        'probation_end_date' => 'date',
        'termination_date' => 'date',
    ];
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function position()
    {
        return $this->belongsTo(Position::class);
    }
    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
    public function subordinates()
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }
    public function contracts()
    {
        return $this->hasMany(EmployeeContract::class);
    }
    public function bankAccounts()
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }
}
