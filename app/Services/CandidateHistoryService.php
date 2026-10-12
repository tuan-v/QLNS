<?php

namespace App\Services;

use App\Models\RecruitmentCandidate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Lịch sử ứng tuyển của 1 người (ngoài tài liệu gốc — người dùng duyệt): tìm theo email
// (không phân biệt hoa thường) HOẶC số điện thoại trên MỌI đợt tuyển, kể cả đợt đã xóa và
// CV đã xóa — để HR biết người này từng nộp chưa, kết quả ra sao. Chỉ để tham khảo, KHÔNG
// chặn nộp lại (trùng trong cùng 1 đợt vẫn do RecruitmentService::assertNotDuplicate chặn).
class CandidateHistoryService
{
    private const LIMIT = 50;

    public function search(?string $email, ?string $phone, ?int $excludeId = null): Collection
    {
        [$email, $phone] = [$this->normalizeEmail($email), $this->normalizePhone($phone)];
        if (! $email && ! $phone) {
            return collect();
        }

        return RecruitmentCandidate::withTrashed()
            ->with([
                'opening' => fn ($q) => $q->withTrashed()->select('id', 'title', 'deleted_at'),
                'interviews' => fn ($q) => $q->latest('scheduled_at'),
                'interviews.scorecard:id,recruitment_interview_id,status,score_percent,pass_percent',
                'latestOffer',
                'hiredEmployee:id,code,full_name',
                'reviewer:id,user_name',
            ])
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->whereRaw('LOWER(email) = ?', [$email]);
                }
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (RecruitmentCandidate $candidate) => $this->present($candidate));
    }

    // Số lần nộp KHÁC của từng ứng viên trong danh sách (1 truy vấn cho cả trang, không N+1).
    /** @param Collection<int, RecruitmentCandidate> $candidates @return array<int, int> */
    public function previousCounts(Collection $candidates): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        $emails = $candidates->pluck('email')->filter()->map(fn ($e) => $this->normalizeEmail($e))->unique()->values()->all();
        $phones = $candidates->pluck('phone')->filter()->map(fn ($p) => $this->normalizePhone($p))->filter()->unique()->values()->all();

        $others = RecruitmentCandidate::withTrashed()
            ->select('id', 'email', 'phone')
            ->where(function ($q) use ($emails, $phones) {
                if ($emails) {
                    $q->whereIn(DB::raw('LOWER(email)'), $emails);
                }
                if ($phones) {
                    $q->orWhereIn('phone', $phones);
                }
            })
            ->get();

        return $candidates->mapWithKeys(function (RecruitmentCandidate $candidate) use ($others) {
            $email = $this->normalizeEmail($candidate->email);
            $phone = $this->normalizePhone($candidate->phone);

            return [$candidate->id => $others->filter(fn ($o) => $o->id !== $candidate->id
                && (($email && $this->normalizeEmail($o->email) === $email) || ($phone && $this->normalizePhone($o->phone) === $phone)))->count()];
        })->all();
    }

    private function present(RecruitmentCandidate $candidate): array
    {
        $interview = $candidate->interviews->first();
        $offer = $candidate->latestOffer;

        return [
            'id' => $candidate->id,
            'full_name' => $candidate->full_name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'status' => $candidate->status,
            'applied_at' => $candidate->created_at,
            'deleted' => $candidate->trashed(),
            'review_note' => $candidate->review_note,
            'reviewed_by' => $candidate->reviewer?->user_name,
            'opening' => $candidate->opening ? [
                'id' => $candidate->opening->id,
                'title' => $candidate->opening->title,
                'deleted' => $candidate->opening->trashed(),
            ] : null,
            'interview' => $interview ? [
                'scheduled_at' => $interview->scheduled_at,
                'status' => $interview->status,
                'result' => $interview->result,
                'confirmation_status' => $interview->confirmation_status,
                'confirmation_note' => $interview->confirmation_note,
                'score_percent' => $interview->scorecard?->status === 'submitted' ? (float) $interview->scorecard->score_percent : null,
            ] : null,
            'offer' => $offer ? [
                'status' => $offer->status,
                'salary' => (float) $offer->salary,
                'response_note' => $offer->response_note,
            ] : null,
            'hired_employee' => $candidate->hiredEmployee ? [
                'id' => $candidate->hiredEmployee->id,
                'code' => $candidate->hiredEmployee->code,
                'full_name' => $candidate->hiredEmployee->full_name,
            ] : null,
        ];
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $phone = preg_replace('/\D+/', '', (string) $phone);

        return $phone !== '' ? $phone : null;
    }
}
