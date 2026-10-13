<?php

namespace App\Services;

use App\Models\InterviewQuestionSet;
use App\Models\InterviewScorecard;
use App\Models\InterviewScorecardItem;
use App\Models\Position;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOpening;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Phiếu chấm phỏng vấn (ngoài tài liệu gốc — người dùng duyệt). Quy tắc:
//  - Admin (recruitment.approve) soạn bộ câu hỏi: câu hỏi + gợi ý + TRỌNG SỐ, % đạt.
//  - Một đợt tuyển dùng ĐƯỢC NHIỀU BỘ (2026-10-13), mỗi bộ 1 TỈ TRỌNG % (tổng 100), vd
//    "Văn hóa chung" 30% + "Chuyên môn Dev" 70%. Chức vụ có bộ MẶC ĐỊNH — đợt tuyển mới của
//    chức vụ tự chép sang (RecruitmentService), Admin vẫn đổi được cho từng đợt.
//  - Bộ dùng để chấm 1 ứng viên: RecruitmentCandidate::effectiveQuestionSets() — bộ riêng
//    Admin chọn cho ứng viên > (đợt tuyển chung) bộ mặc định của vị trí ứng tuyển > bộ của đợt.
//  - HR (manage) + Admin (approve) chấm. Mở phiếu chỉ khi buổi PV còn hẹn và ứng viên ĐÃ
//    ĐỒNG Ý tham gia; đợt tuyển phải có bộ câu hỏi. Phiếu CHÉP câu hỏi/trọng số/tỉ trọng/% đạt
//    — sửa bộ sau đó không đổi phiếu.
//  - Điểm: % từng phần = Σ(trọng số × sao) / Σ(trọng số × 5); % tổng = Σ(% phần × tỉ trọng) /
//    Σ tỉ trọng; % đạt của phiếu = trung bình % đạt các bộ theo tỉ trọng. % tổng >= % đạt ->
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
        return InterviewQuestionSet::with('questions')->withCount(['openings', 'positions'])
            ->orderByDesc('is_active')->orderBy('name')->get();
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

            return $set->load('questions')->loadCount(['openings', 'positions']);
        });
    }

    public function deleteSet(InterviewQuestionSet $set): void
    {
        // Xóa bộ đang dùng sẽ làm tỉ trọng các bộ còn lại không còn đủ 100% -> bắt gỡ trước
        // (hoặc "Ngừng dùng"). Phiếu đã chấm giữ nguyên vì đã chép câu hỏi.
        $openings = $set->openings()->count();
        $positions = $set->positions()->count();
        if ($openings || $positions) {
            throw ValidationException::withMessages([
                'set' => "Bộ câu hỏi đang dùng ở {$openings} đợt tuyển và {$positions} chức vụ — gỡ khỏi các nơi đó hoặc chuyển sang \"Ngừng dùng\" thay vì xóa.",
            ]);
        }

        $set->delete();
    }

    /** @param array<int, array{id: int, weight: int}> $rows */
    public function assignToOpening(RecruitmentOpening $opening, array $rows): RecruitmentOpening
    {
        $this->syncSets($opening->questionSets(), $rows);

        return $opening->load('questionSets:id,name,pass_percent');
    }

    /** @param array<int, array{id: int, weight: int}> $rows */
    public function assignToPosition(Position $position, array $rows): Position
    {
        $this->syncSets($position->questionSets(), $rows);

        return $position->load('questionSets:id,name,pass_percent');
    }

    /** @param array<int, array{id: int, weight: int}> $rows  rỗng = bỏ bộ riêng, quay về mặc định */
    public function assignToCandidate(RecruitmentCandidate $candidate, array $rows): RecruitmentCandidate
    {
        if ($candidate->interviews()->whereHas('scorecard')->exists()) {
            throw ValidationException::withMessages(['question_sets' => 'Ứng viên đã có phiếu chấm — bộ câu hỏi không đổi được nữa.']);
        }

        $this->syncSets($candidate->questionSets(), $rows);

        return $candidate->unsetRelation('questionSets');
    }

    /** Chức vụ đang dùng + bộ câu hỏi mặc định của từng chức vụ (trang Bộ câu hỏi). */
    public function positionDefaults(): SupportCollection
    {
        return Position::active()->with(['department:id,name', 'questionSets:id,name,pass_percent'])
            ->orderBy('name')->get(['id', 'name', 'department_id']);
    }

    // Gắn danh sách bộ (thứ tự = thứ tự gửi lên). Rỗng = bỏ hết. Có bộ thì tỉ trọng phải đủ 100%.
    private function syncSets(BelongsToMany $relation, array $rows): void
    {
        $rows = array_values($rows);
        $ids = array_map(fn ($row) => (int) $row['id'], $rows);

        if ($rows !== []) {
            $active = InterviewQuestionSet::whereIn('id', $ids)->where('is_active', true)->pluck('id')->all();
            if (count($active) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['question_sets' => 'Có bộ câu hỏi không tồn tại hoặc đã ngừng dùng.']);
            }
            $total = array_sum(array_map(fn ($row) => (int) $row['weight'], $rows));
            if ($total !== 100) {
                throw ValidationException::withMessages(['question_sets' => "Tổng tỉ trọng các bộ câu hỏi phải bằng 100% (hiện {$total}%)."]);
            }
        }

        DB::transaction(function () use ($relation, $rows) {
            $relation->sync(collect($rows)->mapWithKeys(fn ($row, $index) => [
                (int) $row['id'] => ['weight' => (int) $row['weight'], 'sort_order' => $index],
            ])->all());
        });
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
            ! $opening || $interview->candidate->effectiveQuestionSets()[0]->isEmpty() => 'Ứng viên chưa có bộ câu hỏi phỏng vấn (Admin gắn cho đợt tuyển, vị trí ứng tuyển hoặc riêng ứng viên).',
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

            $sets = $locked->candidate->effectiveQuestionSets()[0]->load('questions');
            $unusable = $sets->first(fn (InterviewQuestionSet $set) => ! $set->is_active || $set->questions->isEmpty());
            if ($unusable) {
                throw ValidationException::withMessages(['scorecard' => "Bộ câu hỏi \"{$unusable->name}\" của đợt tuyển đang ngừng dùng hoặc chưa có câu hỏi."]);
            }

            $sections = $sets->map(fn (InterviewQuestionSet $set) => [
                'question_set_id' => $set->id,
                'name' => $set->name,
                'weight' => (int) $set->pivot->weight,
                'pass_percent' => (float) $set->pass_percent,
            ])->values()->all();

            $scorecard = $locked->scorecard()->create([
                'interview_question_set_id' => $sets->count() === 1 ? $sets->first()->id : null,
                'question_set_name' => Str::limit($sets->pluck('name')->implode(' + '), 250),
                'pass_percent' => $this->weightedPassPercent($sections),
                'sections' => $sections,
                'status' => InterviewScorecard::STATUS_DRAFT,
                'scored_by' => $actor->id,
            ]);

            $order = 0;
            foreach ($sets->values() as $sectionIndex => $set) {
                foreach ($set->questions as $question) {
                    $scorecard->items()->create([
                        'section_index' => $sectionIndex,
                        'content' => $question->content,
                        'guidance' => $question->guidance,
                        'weight' => $question->weight,
                        'sort_order' => $order++,
                    ]);
                }
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

            $breakdown = $this->breakdown($locked);
            $percent = $breakdown['percent'];
            $result = $percent >= (float) $locked->pass_percent ? 'passed' : 'failed';

            $locked->update([
                'status' => InterviewScorecard::STATUS_SUBMITTED,
                'score_percent' => $percent,
                'result' => $result,
                'submitted_at' => now(),
            ]);

            // Ghi kết quả buổi PV đúng như bấm "Ghi kết quả" (kiểm tra đã tới giờ PV, gửi email).
            $note = count($breakdown['sections']) > 1
                ? 'Phiếu chấm: '.collect($breakdown['sections'])->map(fn ($s) => "{$s['name']} {$s['percent']}% (tỉ trọng {$s['weight']}%)")->implode(' · ')
                    ." → tổng {$percent}% (đạt từ {$locked->pass_percent}%)."
                : "Phiếu chấm \"{$locked->question_set_name}\": {$percent}% (đạt từ {$locked->pass_percent}%).";
            if ($locked->overall_note) {
                $note .= ' '.$locked->overall_note;
            }
            $this->recruitmentService->recordResult($locked->interview->candidate, $result, $note, viaScorecard: true);

            return $locked->fresh(['items', 'scorer:id,user_name']);
        });
    }

    /**
     * % từng phần + % tổng theo tỉ trọng (câu chưa chấm tính 0 sao — dùng cho cả xem tạm lúc
     * đang chấm). Phiếu cũ (chưa có sections) = 1 phần tỉ trọng 100%.
     *
     * @return array{percent: float, sections: array<int, array{name: string, weight: int, pass_percent: float, percent: float}>}
     */
    public function breakdown(InterviewScorecard $scorecard): array
    {
        $items = $scorecard->relationLoaded('items') ? $scorecard->items : $scorecard->items()->get();
        $sections = $scorecard->sections ?: [[
            'question_set_id' => $scorecard->interview_question_set_id,
            'name' => $scorecard->question_set_name,
            'weight' => 100,
            'pass_percent' => (float) $scorecard->pass_percent,
        ]];

        $rows = [];
        foreach (array_values($sections) as $index => $section) {
            $rows[] = $section + ['percent' => $this->percent($items->where('section_index', $index))];
        }

        $totalWeight = array_sum(array_column($rows, 'weight'));
        $percent = $totalWeight > 0
            ? round(array_sum(array_map(fn ($row) => $row['percent'] * $row['weight'], $rows)) / $totalWeight, 2)
            : 0.0;

        return ['percent' => $percent, 'sections' => $rows];
    }

    /** % của 1 nhóm câu: Σ(trọng số × sao) / Σ(trọng số × 5) × 100. */
    public function percent($items): float
    {
        $max = $items->sum(fn ($item) => $item->weight * InterviewScorecard::MAX_SCORE);
        if ($max <= 0) {
            return 0.0;
        }

        return round($items->sum(fn ($item) => $item->weight * (int) $item->score) * 100 / $max, 2);
    }

    private function weightedPassPercent(array $sections): float
    {
        $totalWeight = array_sum(array_column($sections, 'weight'));

        return $totalWeight > 0
            ? round(array_sum(array_map(fn ($s) => $s['pass_percent'] * $s['weight'], $sections)) / $totalWeight, 2)
            : 0.0;
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
            /** @var InterviewScorecardItem|null $item */
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
