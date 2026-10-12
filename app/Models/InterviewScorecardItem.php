<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewScorecardItem extends Model
{
    protected $fillable = ['interview_scorecard_id', 'content', 'guidance', 'weight', 'score', 'note', 'sort_order'];

    protected $casts = [
        'weight' => 'integer',
        'score' => 'integer',
        'sort_order' => 'integer',
    ];
}
