<?php

namespace App\Services;

use App\Models\Position;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOpening;
use Carbon\Carbon;
use Illuminate\Support\Collection;

// Báo cáo tuyển dụng (người dùng duyệt 2026-10-13): phễu từ lúc nhận CV tới nhận việc, thời
// gian xử lý, tỉ lệ nhận offer, hiệu quả theo NGUỒN ứng viên và theo VỊ TRÍ. Lọc theo ngày
// nhận CV (created_at), đợt tuyển, vị trí, nguồn. CV đã xóa không tính.
//
// Không lưu lịch sử trạng thái nên "đã qua bước X" suy ra từ dữ liệu hiện có: CV khác
// chờ duyệt/bị từ chối = đã được duyệt; có buổi PV = được hẹn; buổi PV "completed" = đã
// phỏng vấn; kết quả passed = đạt; offer có sent_at = đã gửi; offer accepted = chấp nhận.
// Gom bằng 1 truy vấn (withExists) rồi đếm trong PHP — dữ liệu tuyển dụng nhỏ, chạy được
// như nhau trên MySQL và SQLite (test).
class RecruitmentReportService
{
    public const STAGES = [
        'received' => 'CV nhận được',
        'approved' => 'CV được duyệt',
        'scheduled' => 'Được hẹn phỏng vấn',
        'interviewed' => 'Đã phỏng vấn',
        'passed' => 'Đạt phỏng vấn',
        'offered' => 'Được gửi offer',
        'accepted' => 'Chấp nhận offer',
        'hired' => 'Nhận việc',
    ];

    private const NOT_APPROVED = [RecruitmentCandidate::STATUS_PENDING, RecruitmentCandidate::STATUS_REJECTED];

    public function report(array $filters): array
    {
        $from = Carbon::parse($filters['from'] ?? now()->startOfYear())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now())->endOfDay();

        $candidates = $this->candidates($from, $to, $filters);
        $rows = $candidates->map(fn (RecruitmentCandidate $c) => $this->stagesOf($c));

        return [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'funnel' => $this->funnel($rows),
            'metrics' => $this->metrics($candidates),
            'by_source' => $this->breakdown($candidates, $rows, fn (RecruitmentCandidate $c) => [
                $c->source ?? 'none',
                RecruitmentCandidate::SOURCES[$c->source] ?? 'Chưa ghi nguồn',
            ]),
            'by_position' => $this->breakdown($candidates, $rows, function (RecruitmentCandidate $c) {
                $position = $c->appliedPosition ?? $c->opening?->position;

                return [$position?->id ?? 'none', $position?->name ?? 'Chưa chọn vị trí'];
            }),
            'options' => $this->options(),
        ];
    }

    private function candidates(Carbon $from, Carbon $to, array $filters): Collection
    {
        return RecruitmentCandidate::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['opening_id'] ?? null, fn ($q, $id) => $q->where('recruitment_opening_id', $id))
            ->when($filters['position_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w
                ->where('applied_position_id', $id)
                ->orWhere(fn ($o) => $o->whereNull('applied_position_id')->whereHas('opening', fn ($op) => $op->where('position_id', $id)))))
            ->when($filters['source'] ?? null, fn ($q, $source) => $source === 'none' ? $q->whereNull('source') : $q->where('source', $source))
            ->withExists([
                'interviews as has_interview',
                'interviews as has_completed_interview' => fn ($q) => $q->where('status', 'completed'),
                'interviews as has_passed_interview' => fn ($q) => $q->where('result', 'passed'),
                'offers as has_sent_offer' => fn ($q) => $q->whereNotNull('sent_at'),
                'offers as has_accepted_offer' => fn ($q) => $q->where('status', RecruitmentOffer::STATUS_ACCEPTED),
            ])
            ->with([
                'appliedPosition:id,name',
                'opening:id,title,position_id',
                'opening.position:id,name',
                'offers:id,recruitment_candidate_id,status,sent_at,responded_at',
            ])
            ->get(['id', 'recruitment_opening_id', 'applied_position_id', 'source', 'status', 'created_at', 'reviewed_at']);
    }

    /** @return array<string, bool> */
    private function stagesOf(RecruitmentCandidate $c): array
    {
        $hired = $c->status === RecruitmentCandidate::STATUS_HIRED;

        return [
            'received' => true,
            'approved' => ! in_array($c->status, self::NOT_APPROVED, true),
            'scheduled' => (bool) $c->has_interview,
            'interviewed' => (bool) $c->has_completed_interview,
            'passed' => (bool) $c->has_passed_interview,
            'offered' => (bool) $c->has_sent_offer,
            'accepted' => (bool) $c->has_accepted_offer || $hired,
            'hired' => $hired,
        ];
    }

    private function funnel(Collection $rows): array
    {
        $total = $rows->count();
        $previous = null;
        $funnel = [];

        foreach (self::STAGES as $key => $label) {
            $count = $rows->where($key, true)->count();
            $funnel[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'percent_of_total' => $total ? round($count * 100 / $total, 1) : 0.0,
                // Tỉ lệ chuyển từ bước trước sang bước này.
                'percent_of_previous' => $previous === null ? null : ($previous ? round($count * 100 / $previous, 1) : 0.0),
            ];
            $previous = $count;
        }

        return $funnel;
    }

    private function metrics(Collection $candidates): array
    {
        $days = fn ($start, $end) => round($start->diffInMinutes($end) / 1440, 1);

        $reviewDays = $candidates->filter(fn ($c) => $c->reviewed_at)->map(fn ($c) => $days($c->created_at, $c->reviewed_at));

        $offers = $candidates->flatMap->offers->filter(fn (RecruitmentOffer $o) => $o->sent_at !== null);
        $accepted = $offers->where('status', RecruitmentOffer::STATUS_ACCEPTED);
        $declined = $offers->where('status', RecruitmentOffer::STATUS_DECLINED)->count();
        $expired = $offers->where('status', RecruitmentOffer::STATUS_EXPIRED)->count();
        $answered = $accepted->count() + $declined + $expired;

        // Thời gian tuyển: từ lúc nhận CV tới lúc ứng viên chấp nhận offer.
        $byId = $candidates->keyBy('id');
        $hireDays = $accepted->filter(fn ($o) => $o->responded_at)
            ->map(fn ($o) => $days($byId[$o->recruitment_candidate_id]->created_at, $o->responded_at));

        $total = $candidates->count();
        $hired = $candidates->where('status', RecruitmentCandidate::STATUS_HIRED)->count();

        return [
            'cv_total' => $total,
            'hired' => $hired,
            'hire_rate' => $total ? round($hired * 100 / $total, 1) : 0.0,
            'avg_review_days' => $reviewDays->isNotEmpty() ? round($reviewDays->avg(), 1) : null,
            'avg_days_to_accept' => $hireDays->isNotEmpty() ? round($hireDays->avg(), 1) : null,
            'offers_sent' => $offers->count(),
            'offers_accepted' => $accepted->count(),
            'offers_declined' => $declined,
            'offers_expired' => $expired,
            'offers_waiting' => $offers->where('status', RecruitmentOffer::STATUS_SENT)->count(),
            'offer_acceptance_rate' => $answered ? round($accepted->count() * 100 / $answered, 1) : null,
        ];
    }

    // Nhóm theo khóa (nguồn / vị trí): số CV ở từng bước chính + tỉ lệ CV -> nhận việc.
    private function breakdown(Collection $candidates, Collection $rows, callable $keyOf): array
    {
        $groups = [];
        foreach ($candidates->values() as $i => $candidate) {
            [$key, $label] = $keyOf($candidate);
            $groups[$key] ??= ['key' => $key, 'label' => $label, 'received' => 0, 'approved' => 0, 'interviewed' => 0, 'passed' => 0, 'hired' => 0];
            foreach (['received', 'approved', 'interviewed', 'passed', 'hired'] as $stage) {
                $groups[$key][$stage] += $rows[$i][$stage] ? 1 : 0;
            }
        }

        return collect($groups)
            ->map(fn ($g) => $g + ['hire_rate' => $g['received'] ? round($g['hired'] * 100 / $g['received'], 1) : 0.0])
            ->sortByDesc('received')
            ->values()
            ->all();
    }

    private function options(): array
    {
        return [
            'openings' => RecruitmentOpening::orderByDesc('id')->get(['id', 'title'])->map(fn ($o) => ['value' => $o->id, 'title' => $o->title])->all(),
            'positions' => Position::orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'title' => $p->name])->all(),
            'sources' => collect(RecruitmentCandidate::SOURCES)->map(fn ($label, $value) => ['value' => $value, 'title' => $label])->values()
                ->push(['value' => 'none', 'title' => 'Chưa ghi nguồn'])->all(),
        ];
    }
}
