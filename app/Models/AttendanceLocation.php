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

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
