<?php

namespace App\Services;

use App\Models\InterviewQuestionSet;
use App\Models\InterviewScorecard;
use App\Models\InterviewScorecardItem;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOpening;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Phiếu chấm phỏng vấn (ngoài tài liệu gốc — người dùng duyệt). Quy tắc:
//  - Admin (recruitment.approve) soạn bộ câu hỏi: câu hỏi + gợi ý + TRỌNG SỐ, % đạt; gắn
//    1 bộ cho mỗi đợt tuyển. HR (manage) + Admin (approve) chấm.
//  - Mở phiếu chỉ khi buổi PV còn hẹn và ứng viên ĐÃ ĐỒNG Ý tham gia; đợt tuyển phải có bộ
//    câu hỏi đang dùng. Phiếu CHÉP câu hỏi/trọng số/% đạt — sửa bộ sau đó không đổi phiếu.
//  - Lưu nháp tùy lúc; nộp: chấm đủ mọi câu (1–5) + đã tới giờ PV (RecruitmentService::
//    recordResult kiểm tra). % = Σ(trọng số × sao) / Σ(trọng số × 5) × 100; % >= % đạt ->
//    Đạt, ngược lại Không đạt — ghi luôn kết quả buổi PV (email kết quả cho ứng viên).
//  - Đợt tuyển có bộ câu hỏi thì KHÔNG ghi kết quả tay được nữa (kết quả luôn khớp điểm).
class InterviewScorecardService
{
    public function __construct(private readonly RecruitmentService $recruitmentService)
    {
    }

    /* ------------------------------ Bộ câu hỏi ------------------------------ */

    /** @return Collection<int, InterviewQuestionSet> */
    public function sets(): Collection
    {
        return InterviewQuestionSet::with('questions')->withCount('openings')->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function saveSet(array $data, User $actor, ?InterviewQuestionSet $set = null): InterviewQuestionSet
    {
        return DB::transaction(function () use ($data, $actor, $set) {
            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'pass_percent' => $data['pass_percent'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ];

            if ($set) {
                $set->update($attributes);
            } else {
                $set = InterviewQuestionSet::create($attributes + ['created_by' => $actor->id]);
            }

            $set->questions()->delete();
            foreach (array_values($data['questions']) as $index => $question) {
                $set->questions()->create([
                    'content' => $question['content'],
                    'guidance' => $question['guidance'] ?? null,
                    'weight' => (int) $question['weight'],
                    'sort_order' => $index,
                ]);
            }

            return $set->load('questions')->loadCount('openings');
        });
    }

    public function deleteSet(InterviewQuestionSet $set): void
    {
        // Đợt tuyển đang gắn bộ này tự về "chưa có bộ câu hỏi" (nullOnDelete); phiếu đã chấm giữ nguyên.
        $set->delete();
    }

    public function assignToOpening(RecruitmentOpening $opening, ?int $setId): RecruitmentOpening
    {
        if ($setId !== null && ! InterviewQuestionSet::whereKey($setId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['interview_question_set_id' => 'Bộ câu hỏi không tồn tại hoặc đã ngừng dùng.']);
        }

        $opening->update(['interview_question_set_id' => $setId]);

        return $opening;
    }

    /* -------------------------------- Phiếu chấm -------------------------------- */

    public function forInterview(RecruitmentInterview $interview): ?InterviewScorecard
    {
        return $interview->scorecard()->with('items', 'scorer:id,user_name')->first();
    }

    // Lý do chưa mở được phiếu (null = mở được) — dùng cho cả API lẫn nút trên giao diện.
    public function startBlocker(RecruitmentInterview $interview): ?string
    {
        $opening = $interview->candidate?->opening;

        return match (true) {
            $interview->status !== RecruitmentInterview::STATUS_SCHEDULED => 'Buổi phỏng vấn này đã có kết quả hoặc đã hủy.',
            $interview->confirmation_status !== RecruitmentInterview::CONFIRMATION_CONFIRMED => 'Chỉ chấm điểm khi ứng viên đã đồng ý tham gia phỏng vấn.',
            ! $opening?->interview_question_set_id => 'Đợt tuyển chưa được Admin gắn bộ câu hỏi phỏng vấn.',
            default => null,
        };
    }

    public function start(RecruitmentInterview $interview, User $actor): InterviewScorecard
    {
        return DB::transaction(function () use ($interview, $actor) {
            $locked = RecruitmentInterview::with('candidate.opening')->whereKey($interview->id)->lockForUpdate()->firstOrFail();

            if ($existing = $locked->scorecard()->first()) {
                return $existing->load('items', 'scorer:id,user_name');
            }

            if ($reason = $this->startBlocker($locked)) {
                throw ValidationException::withMessages(['scorecard' => $reason]);
            }

            $set = InterviewQuestionSet::with('questions')->find($locked->candidate->opening->interview_question_set_id);
            if (! $set || ! $set->is_active || $set->questions->isEmpty()) {
                throw ValidationException::withMessages(['scorecard' => 'Bộ câu hỏi của đợt tuyển đang ngừng dùng hoặc chưa có câu hỏi.']);
            }

            $scorecard = $locked->scorecard()->create([
                'interview_question_set_id' => $set->id,
                'question_set_name' => $set->name,
                'pass_percent' => $set->pass_percent,
                'status' => InterviewScorecard::STATUS_DRAFT,
                'scored_by' => $actor->id,
            ]);

            foreach ($set->questions as $question) {
                $scorecard->items()->create([
                    'content' => $question->content,
                    'guidance' => $question->guidance,
                    'weight' => $question->weight,
                    'sort_order' => $question->sort_order,
                ]);
            }

            return $scorecard->load('items', 'scorer:id,user_name');
        });
    }

    public function saveDraft(InterviewScorecard $scorecard, array $data, User $actor): InterviewScorecard
    {
        return DB::transaction(function () use ($scorecard, $data, $actor) {
            $locked = $this->lockEditable($scorecard);
            $this->applyScores($locked, $data, $actor);

            return $locked->fresh(['items', 'scorer:id,user_name']);
        });
    }

    public function submit(InterviewScorecard $scorecard, array $data, User $actor): InterviewScorecard
    {
        return DB::transaction(function () use ($scorecard, $data, $actor) {
            $locked = $this->lockEditable($scorecard);
            $this->applyScores($locked, $data, $actor);
            $locked->load('items', 'interview.candidate');

            $missing = $locked->items->whereNull('score')->count();
            if ($missing > 0) {
                throw ValidationException::withMessages(['items' => "Còn {$missing} câu chưa chấm điểm."]);
            }

            $percent = $this->percent($locked->items);
            $result = $percent >= (float) $locked->pass_percent ? 'passed' : 'failed';

            $locked->update([
                'status' => InterviewScorecard::STATUS_SUBMITTED,
                'score_percent' => $percent,
                'result' => $result,
                'submitted_at' => now(),
            ]);

            // Ghi kết quả buổi PV đúng như bấm "Ghi kết quả" (kiểm tra đã tới giờ PV, gửi email).
            $note = "Phiếu chấm \"{$locked->question_set_name}\": {$percent}% (đạt từ {$locked->pass_percent}%).";
            if ($locked->overall_note) {
                $note .= ' '.$locked->overall_note;
            }
            $this->recruitmentService->recordResult($locked->interview->candidate, $result, $note, viaScorecard: true);

            return $locked->fresh(['items', 'scorer:id,user_name']);
        });
    }

    /** @param \Illuminate\Support\Collection<int, InterviewScorecardItem> $items */
    public function percent($items): float
    {
        $max = $items->sum(fn ($item) => $item->weight * InterviewScorecard::MAX_SCORE);
        if ($max <= 0) {
            return 0.0;
        }

        return round($items->sum(fn ($item) => $item->weight * (int) $item->score) * 100 / $max, 2);
    }

    private function lockEditable(InterviewScorecard $scorecard): InterviewScorecard
    {
        $locked = InterviewScorecard::whereKey($scorecard->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== InterviewScorecard::STATUS_DRAFT) {
            throw ValidationException::withMessages(['scorecard' => 'Phiếu chấm đã nộp — không sửa được nữa.']);
        }

        return $locked;
    }

    private function applyScores(InterviewScorecard $scorecard, array $data, User $actor): void
    {
        $items = $scorecard->items()->get()->keyBy('id');

        foreach ($data['items'] ?? [] as $row) {
            $item = $items->get((int) $row['id']);
            if (! $item) {
                throw ValidationException::withMessages(['items' => 'Câu hỏi không thuộc phiếu chấm này.']);
            }
            $item->update([
                'score' => $row['score'] ?? null,
                'note' => $row['note'] ?? null,
            ]);
        }

        $scorecard->update([
            'overall_note' => $data['overall_note'] ?? $scorecard->overall_note,
            'scored_by' => $actor->id,
        ]);
    }
}
