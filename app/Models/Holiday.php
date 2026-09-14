<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    use SoftDeletes;

    protected $fillable = ['holiday_date', 'name', 'is_paid', 'work_coefficient'];

    protected $casts = [
        'holiday_date' => 'date:Y-m-d',
        'is_paid' => 'boolean',
        'work_coefficient' => 'float',
    ];
}
