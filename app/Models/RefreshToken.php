<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    public const UPDATED_AT = null; // bảng chỉ có created_at

    protected $fillable = [
        'user_id',
        'jwt_id',
        'token_hash',
        'device_name',
        'ip_address',
        'user_agent',
        'expires_at',
        'revoked_at',
        'rotated_at',
        'replaced_by_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Token mới được cấp khi xoay vòng token này.
    public function replacedBy()
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
