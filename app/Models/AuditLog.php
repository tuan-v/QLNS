<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'action',
        'auditable_type',
        'auditable_id',
        'old_data',
        'new_data',
        'http_method',
        'url',
        'request_id',
    ];
    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array'
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public $timestamps = false;
}
