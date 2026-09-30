<?php

namespace App\Models;

use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use BroadcastsChanges;

    protected array $realtimeShared = ['payrolls'];

    protected array $realtimeOwn = ['payslips'];

    /** @return array<int, int> */
    protected function realtimeEmployeeIds(): array
    {
        return PayrollDetail::where('payroll_id', $this->id)->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
    }

    protected $fillable = [
        'period_month',
        'period_year',
        'status',
        'currency',
        'created_by',
        'closed_by',
        'closed_at',
        'paid_at',
        'total_payroll_amount',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'paid_at' => 'datetime',
        'total_payroll_amount' => 'decimal:2',
    ];

    public function details()
    {
        return $this->hasMany(PayrollDetail::class);
    }
    // belongsTo() mặc định đoán cột khóa ngoại là "user_id" (tên_model + "_id").
    // Bảng payrolls có 2 khóa ngoại cùng trỏ tới users (created_by, closed_by)
    // nên phải truyền tay tên cột thật ở tham số thứ 2, nếu không Eloquent sẽ
    // tìm nhầm cột "user_id" (không tồn tại) và query sẽ lỗi.
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
