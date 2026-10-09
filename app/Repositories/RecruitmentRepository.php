<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOpening;
use Illuminate\Pagination\LengthAwarePaginator;

class RecruitmentRepository
{
    // Danh sách đợt tuyển kèm số CV: đã duyệt (chiếm suất), chờ duyệt, tổng.
    public function paginateOpenings(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return RecruitmentOpening::query()
            ->with(['department:id,name', 'position:id,name'])
            ->withCount([
                'candidates as approved_count' => fn ($q) => $q->whereIn('status', RecruitmentCandidate::COUNTED_STATUSES),
                'candidates as pending_count' => fn ($q) => $q->where('status', RecruitmentCandidate::STATUS_PENDING),
                'candidates as candidates_count',
            ])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('title', 'like', "%{$search}%"))
            ->latest()
            ->paginate($perPage);
    }

    public function loadOpeningDetail(RecruitmentOpening $opening): RecruitmentOpening
    {
        return $opening->load([
            'department:id,name', 'position:id,name', 'creator:id,user_name',
            'candidates' => fn ($q) => $q->latest(),
            'candidates.submitter:id,user_name', 'candidates.reviewer:id,user_name',
            'candidates.interviews.interviewer:id,full_name', 'candidates.hiredEmployee:id,code,full_name',
            'candidates.latestOffer.creator:id,user_name', 'candidates.latestOffer.reviewer:id,user_name',
        ])->loadCount([
            'candidates as approved_count' => fn ($q) => $q->whereIn('status', RecruitmentCandidate::COUNTED_STATUSES),
            'candidates as pending_count' => fn ($q) => $q->where('status', RecruitmentCandidate::STATUS_PENDING),
        ]);
    }

    // Số CV đang chiếm suất của đợt tuyển — luôn đếm trực tiếp từ DB (không tin số
    // đã nạp sẵn) vì dùng để chặn vượt giới hạn bên trong transaction đã khóa đợt tuyển.
    public function countedCandidates(RecruitmentOpening $opening): int
    {
        return RecruitmentCandidate::where('recruitment_opening_id', $opening->id)
            ->whereIn('status', RecruitmentCandidate::COUNTED_STATUSES)
            ->count();
    }

    public function emailAlreadySubmitted(RecruitmentOpening $opening, string $email): bool
    {
        return RecruitmentCandidate::where('recruitment_opening_id', $opening->id)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();
    }

    public function phoneAlreadySubmitted(RecruitmentOpening $opening, string $phone): bool
    {
        return RecruitmentCandidate::where('recruitment_opening_id', $opening->id)
            ->where('phone', $phone)
            ->exists();
    }

    // Hồ sơ nhân viên (kể cả đã xóa mềm — cùng phạm vi rule unique của StoreEmployeeRequest)
    // đang dùng email này làm email cá nhân hoặc email công ty.
    public function employeeWithEmail(string $email): ?Employee
    {
        $email = mb_strtolower($email);

        return Employee::withTrashed()
            ->where(fn ($q) => $q->whereRaw('LOWER(personal_email) = ?', [$email])->orWhereRaw('LOWER(company_email) = ?', [$email]))
            ->first(['id', 'code', 'full_name', 'deleted_at']);
    }

    public function employeeWithPhone(string $phone): ?Employee
    {
        return Employee::withTrashed()->where('phone', $phone)->first(['id', 'code', 'full_name', 'deleted_at']);
    }
}
