<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOpening;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Trang "Lịch phỏng vấn" (HR + Admin) và phản hồi của ứng viên với lịch hẹn. Quy tắc:
//  - HR (recruitment.manage) ghi nhận ứng viên: đồng ý / xin đổi lịch / không tham gia.
//    Mỗi lần ghi nhận -> báo người có recruitment.approve (Admin).
//  - Chỉ ghi nhận cho lịch đang hẹn ('scheduled'); ghi lại được (vd đồng ý rồi báo đổi lịch).
//  - "Xin đổi lịch": giữ ứng viên + suất, HR dời lịch (RecruitmentService::scheduleInterview
//    sửa chính lịch đó và đưa phản hồi về "chờ xác nhận").
//  - "Không tham gia": lịch 'cancelled', ứng viên "Từ chối phỏng vấn", TRẢ LẠI SUẤT.
class RecruitmentInterviewService
{
    public function __construct(
        private readonly RecruitmentService $recruitmentService,
        private readonly NotificationService $notificationService,
    ) {
    }

    /** @return array{interviews: Collection<int, RecruitmentInterview>, summary: array<string, int>} */
    /** @return array<int, array{id: int, title: string, subtitle: string}> */
    public function interviewers(): array
    {
        return Employee::whereIn('employment_status', Employee::WORKING_STATUSES)
            ->with(['department:id,name', 'position:id,name'])
            ->orderBy('full_name')
            ->get(['id', 'code', 'full_name', 'department_id', 'position_id'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'title' => "{$e->code} – {$e->full_name}",
                'subtitle' => implode(' · ', array_filter([$e->position?->name, $e->department?->name])),
            ])
            ->all();
    }

    // Nhóm buổi PV (không trùng nhau, cộng lại = tổng) — khớp giai đoạn ứng viên
    // (RecruitmentCandidate::stageFor): chờ xác nhận / xin đổi lịch / sắp PV (đã đồng ý, chưa
    // tới giờ) / chờ ghi kết quả (đã đồng ý, tới giờ) / đã PV / không tham gia.
    public const BUCKETS = ['pending', 'reschedule_requested', 'upcoming', 'awaiting_result', 'completed', 'cancelled'];

    public static function bucketOf(RecruitmentInterview $interview, ?Carbon $now = null): string
    {
        $now ??= now();

        return match (true) {
            $interview->status === RecruitmentInterview::STATUS_COMPLETED => 'completed',
            $interview->status === RecruitmentInterview::STATUS_CANCELLED => 'cancelled',
            $interview->confirmation_status === RecruitmentInterview::CONFIRMATION_RESCHEDULE => 'reschedule_requested',
            $interview->confirmation_status === RecruitmentInterview::CONFIRMATION_CONFIRMED => $interview->scheduled_at->lte($now) ? 'awaiting_result' : 'upcoming',
            default => 'pending',
        };
    }

    public function month(string $month, array $filters = []): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $base = RecruitmentInterview::query()
            ->whereBetween('scheduled_at', [$start, $end])
            ->whereHas('candidate')
            ->when($filters['opening_id'] ?? null, fn ($q, $id) => $q->whereHas('candidate', fn ($c) => $c->where('recruitment_opening_id', $id)))
            ->when($filters['interviewer_id'] ?? null, fn ($q, $id) => $id === 'none' ? $q->whereNull('interviewer_id') : $q->where('interviewer_id', $id));

        $interviews = $base
            ->with([
                'candidate:id,recruitment_opening_id,full_name,email,phone,status',
                'candidate.opening:id,title,department_id,headcount,status',
                'candidate.opening.questionSets:id',
                'candidate.questionSets:id',
                'candidate.appliedPosition:id,name',
                'candidate.appliedPosition.questionSets:id',
                'scorecard:id,recruitment_interview_id,status,score_percent,result',
                'candidate.opening.department:id,name',
                'confirmer:id,user_name',
                'interviewer:id,full_name',
            ])
            ->orderBy('scheduled_at')
            ->get();

        // Số liệu tính TRƯỚC khi lọc theo nhóm (thẻ số liệu không đổi khi bấm lọc).
        $now = now();
        $buckets = $interviews->groupBy(fn (RecruitmentInterview $i) => self::bucketOf($i, $now));
        $completed = $buckets->get('completed', collect());

        $bucket = $filters['bucket'] ?? null;

        return [
            'interviews' => $bucket ? $buckets->get($bucket, collect())->values() : $interviews,
            'summary' => ['total' => $interviews->count()]
                + collect(self::BUCKETS)->mapWithKeys(fn ($key) => [$key => $buckets->get($key, collect())->count()])->all()
                + [
                    'passed' => $completed->where('result', 'passed')->count(),
                    'failed' => $completed->where('result', 'failed')->count(),
                ],
        ];
    }

    public function recordConfirmation(RecruitmentInterview $interview, string $status, ?string $note, User $actor): RecruitmentInterview
    {
        $interview = DB::transaction(function () use ($interview, $status, $note, $actor) {
            $candidate = RecruitmentCandidate::whereKey($interview->recruitment_candidate_id)->lockForUpdate()->firstOrFail();
            $opening = RecruitmentOpening::whereKey($candidate->recruitment_opening_id)->lockForUpdate()->firstOrFail();
            $locked = RecruitmentInterview::whereKey($interview->id)->lockForUpdate()->firstOrFail();

            if (
                $locked->status !== RecruitmentInterview::STATUS_SCHEDULED
                || $candidate->status !== RecruitmentCandidate::STATUS_INTERVIEW_SCHEDULED
            ) {
                throw ValidationException::withMessages(['status' => 'Lịch phỏng vấn này đã có kết quả hoặc đã hủy — không ghi nhận được nữa.']);
            }

            $withdrawn = $status === RecruitmentInterview::CONFIRMATION_WITHDRAWN;

            $locked->update([
                'confirmation_status' => $status,
                'confirmation_note' => $note,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'status' => $withdrawn ? RecruitmentInterview::STATUS_CANCELLED : $locked->status,
            ]);

            if ($withdrawn) {
                $candidate->forceFill(['status' => RecruitmentCandidate::STATUS_INTERVIEW_DECLINED])->save();
                $this->recruitmentService->syncOpeningStatus($opening);
            }

            return $locked;
        });

        $interview->load('candidate.opening');
        $this->notifyAdmins($interview, $actor);

        return $interview;
    }

    private function notifyAdmins(RecruitmentInterview $interview, User $actor): void
    {
        $candidate = $interview->candidate;
        $time = $interview->scheduled_at->format('H:i d/m/Y');
        [$title, $message] = match ($interview->confirmation_status) {
            RecruitmentInterview::CONFIRMATION_CONFIRMED => ['Ứng viên đồng ý phỏng vấn', "{$candidate->full_name} đồng ý tham gia phỏng vấn lúc {$time}."],
            RecruitmentInterview::CONFIRMATION_RESCHEDULE => ['Ứng viên xin đổi lịch phỏng vấn', "{$candidate->full_name} xin đổi lịch phỏng vấn lúc {$time} — HR sẽ hẹn lại."],
            default => ['Ứng viên từ chối phỏng vấn', "{$candidate->full_name} không tham gia phỏng vấn nữa — đã trả lại suất cho đợt tuyển."],
        };
        if ($interview->confirmation_note) {
            $message .= " Ghi chú: {$interview->confirmation_note}";
        }
        $message .= " (HR: {$actor->user_name}, đợt \"{$candidate->opening->title}\")";

        foreach (User::withPermission('recruitment.approve')->where('id', '!=', $actor->id)->get() as $admin) {
            $this->notificationService->send($admin, 'recruitment.interview_confirmation', $title, $message, [
                'recruitment_opening_id' => $candidate->recruitment_opening_id,
                'candidate_id' => $candidate->id,
                'interview_id' => $interview->id,
                'month' => $interview->scheduled_at->format('Y-m'),
                'status' => $interview->confirmation_status === RecruitmentInterview::CONFIRMATION_CONFIRMED ? 'approved' : 'rejected',
            ]);
        }
    }
}
