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
        'address_detail',
        'province_code',
        'commune_code',
        'personal_tax_code',
        'avatar',
        'hire_date',
        'probation_end_date',
        'termination_date',
        'employment_status',
    ];
    // 'date:Y-m-d' (không phải 'date' trần) — bắt buộc với mọi cột chỉ lưu NGÀY:
    // 'date' trần khi ra JSON bị Carbon tự quy đổi sang UTC ("2005-02-08T17:00:00Z"
    // thay vì "2005-02-09"), vì app chạy múi giờ Asia/Ho_Chi_Minh (UTC+7, xem
    // config/app.php) nên nửa đêm giờ VN rơi vào 17h hôm trước theo UTC. Frontend
    // (EmployeeForm.vue::toDateInput()) chỉ cắt 10 ký tự đầu của chuỗi ISO, đọc
    // nhầm sang ngày hôm trước — sửa xong lưu lại không đổi gì cũng tự lùi thêm
    // 1 ngày mỗi lần. Chỉ định dạng thẳng ở cast thì Carbon không còn cơ hội quy
    // đổi giờ/múi giờ nữa, JSON luôn ra đúng "YYYY-MM-DD".
    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'hire_date' => 'date:Y-m-d',
        'probation_end_date' => 'date:Y-m-d',
        'termination_date' => 'date:Y-m-d',
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
    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class);
    }
    public function transfers()
    {
        return $this->hasMany(EmployeeTransfer::class);
    }
    public function shiftAssignments()
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }
    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }
    public function bankAccounts()
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }
    public function province()
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }
    public function commune()
    {
        return $this->belongsTo(Commune::class, 'commune_code', 'code');
    }
}
