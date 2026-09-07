<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'code',
        'name',
    ];

    public function communes()
    {
        return $this->hasMany(Commune::class, 'province_code', 'code');
    }
}
