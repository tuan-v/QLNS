<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    // Bảng chỉ có "created_at" (DB tự set qua useCurrent()), không có
    // "updated_at" — tắt timestamps tự động của Eloquent để tránh lỗi khi
    // save() cố gán cột "updated_at" không tồn tại.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token_hash',
        'otp_hash',
        'requested_ip',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
