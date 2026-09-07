<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commune extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'code',
        'name',
        'province_code',
    ];

    public function province()
    {
        return $this->belongsTo(Province::class, 'province_code', 'code');
    }
}
