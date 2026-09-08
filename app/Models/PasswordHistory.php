<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordHistory extends Model
{
    // Chỉ có "changed_at" (DB tự set qua useCurrent()), không có cặp
    // created_at/updated_at chuẩn của Eloquent.
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'password_hash',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
