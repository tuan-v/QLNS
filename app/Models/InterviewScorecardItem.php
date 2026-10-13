<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewScorecardItem extends Model
{
    protected $fillable = ['interview_scorecard_id', 'section_index', 'content', 'guidance', 'weight', 'score', 'note', 'sort_order'];

    protected $casts = [
        'section_index' => 'integer',
        'weight' => 'integer',
        'score' => 'integer',
        'sort_order' => 'integer',
    ];
}
