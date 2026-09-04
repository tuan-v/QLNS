<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use Auditable;
    use SoftDeletes;
    protected $fillable = [
        'department_id',
        'code',
        'name',
        'level',
        'position_allowance',
        'is_active',
    ];
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
