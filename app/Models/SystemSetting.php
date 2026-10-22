<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

// Cài đặt chung của hệ thống dạng khóa → giá trị (2026-10-22). Hiện có: office_latitude, office_longitude
// (xem App\Services\Attendance\OfficeLocation).
class SystemSetting extends Model
{
    use Auditable;

    protected $fillable = ['key', 'value', 'updated_by'];

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
