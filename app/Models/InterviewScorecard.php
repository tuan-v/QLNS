<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// Phiếu chấm của 1 buổi phỏng vấn — câu hỏi được CHÉP từ các bộ câu hỏi lúc tạo phiếu.
// sections: [{question_set_id, name, weight, pass_percent}] — mỗi bộ 1 phần (null = phiếu cũ, 1 phần).
class InterviewScorecard extends Model
{
    use Auditable;
    use BroadcastsChanges;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const MAX_SCORE = 5;

    protected array $realtimeShared = ['recruitment'];

    protected $fillable = [
        'recruitment_interview_id', 'interview_question_set_id', 'question_set_name', 'pass_percent', 'sections',
        'status', 'score_percent', 'result', 'overall_note', 'scored_by', 'submitted_at',
    ];

    protected $casts = [
        'pass_percent' => 'decimal:2',
        'sections' => 'array',
        'score_percent' => 'decimal:2',
        'submitted_at' => 'datetime',
    ];

    public function interview()
    {
        return $this->belongsTo(RecruitmentInterview::class, 'recruitment_interview_id');
    }

    public function items()
    {
        return $this->hasMany(InterviewScorecardItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scorer()
    {
        return $this->belongsTo(User::class, 'scored_by');
    }
}
