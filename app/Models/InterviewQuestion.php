<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewQuestion extends Model
{
    protected $fillable = ['interview_question_set_id', 'content', 'guidance', 'weight', 'sort_order'];

    protected $casts = [
        'weight' => 'integer',
        'sort_order' => 'integer',
    ];
}
