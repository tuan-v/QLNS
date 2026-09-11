<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use Auditable;
    protected $fillable = [
        'code',
        'name',
        'annual_entitlement_days',
        'is_paid',
        'allow_carry_forward',
        'max_carry_forward_days',
        'is_active',
    ];
    // Cùng lý do ép float ở LeaveRequest::$casts — cột decimal MySQL trả về
    // chuỗi thô ("12.00") nếu không ép kiểu, hiện xấu ở Frontend.
    protected $casts = [
        'annual_entitlement_days' => 'float',
        'max_carry_forward_days' => 'float',
        'is_paid' => 'boolean',
        'allow_carry_forward' => 'boolean',
        'is_active' => 'boolean',
    ];
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
