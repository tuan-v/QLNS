<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;

class User extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'email',
        'user_name',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['assigned_by', 'assigned_at']);
    }

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    /** Danh sách code permission (dùng cho middleware & JWT payload). */
    public function permissionCodes(): array
    {
        return $this->roles()
            ->with('permissions:id,code')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('code')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Bản có cache (60s) của permissionCodes() — dùng ở mọi nơi cần kiểm tra
     * quyền ngoài request thường (vd closure xác thực kênh WebSocket), tránh
     * mỗi nơi tự viết Cache::remember riêng rồi lệch key/TTL với nhau.
     */
    public function cachedPermissionCodes(): array
    {
        return Cache::remember("permission:{$this->id}", 60, fn () => $this->permissionCodes());
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->cachedPermissionCodes(), true);
    }

    /** Chỉ lấy user đang có 1 permission cụ thể (qua Role đang giữ). */
    public function scopeWithPermission(Builder $query, string $code): Builder
    {
        return $query->whereHas('roles.permissions', fn ($q) => $q->where('code', $code));
    }
}
