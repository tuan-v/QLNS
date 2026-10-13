<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// Bộ câu hỏi phỏng vấn do Admin soạn (trọng số từng câu + % đạt) — gắn cho đợt tuyển
// (nhiều bộ/đợt, có tỉ trọng) và làm mặc định cho chức vụ.
class InterviewQuestionSet extends Model
{
    use Auditable;
    use BroadcastsChanges;

    protected array $realtimeShared = ['recruitment'];

    protected $fillable = ['name', 'description', 'pass_percent', 'is_active', 'created_by'];

    protected $casts = [
        'pass_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function questions()
    {
        return $this->hasMany(InterviewQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function openings()
    {
        return $this->belongsToMany(RecruitmentOpening::class, 'recruitment_opening_question_sets');
    }

    public function positions()
    {
        return $this->belongsToMany(Position::class, 'position_question_sets');
    }
}
