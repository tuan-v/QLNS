<?php

namespace App\Models;

use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// 1 lần cộng/trừ số dư phép (LeaveBalanceService, 2026-10-15). Số dư thực tế vẫn nằm ở
// leave_balances (carried_forward_days / adjusted_days) — bảng này là LỊCH SỬ để giải thích.
class LeaveBalanceAdjustment extends Model
{
    use BroadcastsChanges;

    protected array $realtimeShared = ['employees'];

    protected array $realtimeOwn = ['leave_balances'];

    public const TYPE_MANUAL = 'manual';

    public const TYPE_CARRY_FORWARD = 'carry_forward';

    public const TYPE_CARRY_EXPIRED = 'carry_expired';

    protected $fillable = ['leave_balance_id', 'employee_id', 'leave_type_id', 'year', 'type', 'days', 'reason', 'created_by'];

    protected $casts = [
        'days' => 'float',
        'year' => 'integer',
    ];

    public function balance()
    {
        return $this->belongsTo(LeaveBalance::class, 'leave_balance_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
