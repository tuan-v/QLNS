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
}
