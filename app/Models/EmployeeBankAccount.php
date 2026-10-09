<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// Tài khoản ngân hàng nhận lương — xem EmployeeBankAccountService.
class EmployeeBankAccount extends Model
{
    use Auditable;
    use BroadcastsChanges;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    protected array $realtimeShared = ['employee_bank_accounts'];

    protected array $realtimeOwn = ['bank_accounts'];

    protected $fillable = [
        'employee_id',
        'bank_code',
        'logo_bank',
        'bank_name',
        'bank_branch',
        'account_number',
        'account_holder',
        'is_primary',
        'status',
        'created_by',
        'verified_by',
        'verified_at',
        'review_note',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
