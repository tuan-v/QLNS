<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use Auditable;
    use SoftDeletes;
    protected $fillable = [
        'parent_id',
        'manager_id',
        'code',
        'name',
        'description',
        'is_active',
    ];
    public function parent()
    {
        return $this->belongsTo(Department::class, 'parent_id', 'id');
    }
    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id', 'id');
    }
    public function children()
    {
        return $this->hasMany(Department::class, 'parent_id', 'id');
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    /**
     * Các phòng ban gốc + mọi phòng con cháu (dùng cho phạm vi xem của Trưởng phòng ở Báo cáo, KPI).
     *
     * @param  array<int, int>  $roots
     * @return array<int, int>
     */
    public static function withDescendantIds(array $roots): array
    {
        $all = $roots;
        $frontier = $roots;
        while ($frontier !== []) {
            $frontier = static::whereIn('parent_id', $frontier)->whereNotIn('id', $all)->pluck('id')->all();
            $all = array_merge($all, $frontier);
        }

        return array_values(array_unique($all));
    }
}
