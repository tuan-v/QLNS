<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceLocation extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'method',
        'wifi_ssid',
        'allowed_ip_cidr',
        'latitude',
        'longitude',
        'radius_meters',
        'qr_secret',
        'is_active',
    ];

    // Cùng lý do ép float ở WorkShift::$casts. KHÔNG ép is_active thành
    // boolean — cùng lý do đã ghi ở WorkShift::$casts (AttendanceLocations.vue
    // map trạng thái theo khóa 1/0).
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
