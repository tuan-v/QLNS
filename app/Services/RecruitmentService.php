<?php

namespace App\Services;

use App\Mail\CandidateInterviewInvitationMail;
use App\Mail\CandidateInterviewResultMail;
use App\Mail\CandidateReviewRejectedMail;
use App\Models\Commune;
use App\Models\Employee;
use App\Models\Province;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOpening;
use App\Models\User;
use App\Repositories\RecruitmentRepository;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Tuyển dụng. Quy tắc giữ đúng logic:
//  - Giới hạn: số CV "chiếm suất" (RecruitmentCandidate::COUNTED_STATUSES) không vượt
//    headcount (null = không giới hạn — đợt "tuyển dụng chung"). Đủ thì đợt tuyển tự
//    "full" và ngưng nhận CV; CV bị từ chối hoặc trượt
//    phỏng vấn trả lại suất (đợt tuyển tự mở lại nếu chưa bị đóng tay).
//  - Mọi thao tác làm đổi số CV chiếm suất / nhận CV mới đều KHÓA dòng đợt tuyển
//    (lockForUpdate) trong transaction -> 2 người thao tác cùng lúc không vượt giới hạn.
//  - Trạng thái ứng viên chỉ đi đúng chiều (xem RecruitmentCandidate); sai bước -> 422.
//  - Email cho ứng viên + thông báo nội bộ chỉ gửi SAU khi transaction đã commit.
//  - Đạt phỏng vấn -> thư mời nhận việc (RecruitmentOfferService) -> Nhận việc chỉ khi
//    ứng viên đã chấp nhận offer.
class RecruitmentService
{
    public const CV_DISK = 'local';

    public const CV_DIRECTORY = 'recruitment-cvs';

    public function __construct(
        private readonly RecruitmentRepository $recruitmentRepository,
        private readonly NotificationService $notificationService,
        private readonly CandidateHistoryService $candidateHistoryService,
    ) {
    }

    /* ------------------------------- Đợt tuyển ------------------------------- */

    public function listOpenings(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->recruitmentRepository->paginateOpenings($filters, $perPage);
    }

    public function showOpening(RecruitmentOpening $opening): RecruitmentOpening
    {
        $opening = $this->recruitmentRepository->loadOpeningDetail($opening);
        // Gắn ngược đợt tuyển cho từng ứng viên -> effectiveQuestionSets() không truy vấn lại.
        $opening->candidates->each(fn (RecruitmentCandidate $c) => $c->setRelation('opening', $opening));

        // Số lần người này từng nộp CV khác (mọi đợt tuyển) — chip "Đã nộp N lần trước".
        $counts = $this->candidateHistoryService->previousCounts($opening->candidates);
        $opening->candidates->each(fn (RecruitmentCandidate $c) => $c->setAttribute('previous_applications_count', $counts[$c->id] ?? 0));

        return $opening;
    }

    public function createOpening(array $data, User $actor): RecruitmentOpening
    {
        $opening = DB::transaction(function () use ($data, $actor) {
            $opening = RecruitmentOpening::create($this->normalizeOpening($data) + [
                'status' => RecruitmentOpening::STATUS_OPEN,
                'created_by' => $actor->id,
            ]);
            $this->applyPositionQuestionSets($opening);

            return $opening;
        });

        $this->notifyOpeningCreated($opening, $actor);

        return $opening;
    }

    // Mở đợt tuyển -> báo những người còn lại có quyền tuyển dụng (HR mở thì Admin biết và
    // ngược lại, người dùng yêu cầu 2026-10-13). Gửi sau khi transaction đã commit.
    private function notifyOpeningCreated(RecruitmentOpening $opening, User $actor): void
    {
        $opening->loadMissing('department:id,name', 'position:id,name');
        $where = $opening->isGeneral()
            ? 'Tuyển dụng chung'
            : implode(' · ', array_filter([$opening->department?->name, $opening->position?->name]));
        $details = implode(' · ', array_filter([
            $where,
            $opening->headcount ? "cần {$opening->headcount} người" : 'không giới hạn số lượng',
            $opening->deadline ? 'hạn nộp CV '.$opening->deadline->format('d/m/Y') : null,
        ]));

        $recipients = User::where('status', 'active')
            ->where('id', '!=', $actor->id)
            ->whereHas('roles.permissions', fn ($q) => $q->whereIn('code', ['recruitment.manage', 'recruitment.approve']))
            ->get();

        foreach ($recipients as $user) {
            $this->notificationService->send(
                $user,
                'recruitment.opening_created',
                'Đợt tuyển dụng mới',
                "{$actor->user_name} vừa mở đợt tuyển \"{$opening->title}\" ({$details}).",
                ['recruitment_opening_id' => $opening->id],
            );
        }
    }

    // Đợt tuyển chung (không phòng ban): không chức vụ, không giới hạn số lượng.
    private function normalizeOpening(array $data): array
    {
        if (empty($data['department_id'])) {
            $data['department_id'] = null;
            $data['position_id'] = null;
            $data['headcount'] = null;
        }

        return $data;
    }

    // Đợt tuyển CHƯA có bộ câu hỏi nào -> chép bộ mặc định (kèm tỉ trọng) của chức vụ.
    // Đã có (Admin đã chọn) thì giữ nguyên, kể cả khi đổi chức vụ.
    private function applyPositionQuestionSets(RecruitmentOpening $opening): void
    {
        if (! $opening->position_id || $opening->questionSets()->exists()) {
            return;
        }

        $defaults = $opening->position()->first()?->questionSets()->where('is_active', true)->get() ?? collect();
        if ($defaults->isEmpty() || (int) $defaults->sum('pivot.weight') !== 100) {
            return; // bộ mặc định có bộ đã ngừng dùng -> tỉ trọng lệch, để Admin tự chọn
        }

        $opening->questionSets()->sync($defaults->mapWithKeys(fn ($set) => [
            $set->id => ['weight' => (int) $set->pivot->weight, 'sort_order' => (int) $set->pivot->sort_order],
        ])->all());
    }

    public function updateOpening(RecruitmentOpening $opening, array $data): RecruitmentOpening
    {
        return DB::transaction(function () use ($opening, $data) {
            $locked = $this->lockOpening($opening);
            $counted = $this->recruitmentRepository->countedCandidates($locked);

            if (($data['headcount'] ?? null) !== null && (int) $data['headcount'] < $counted) {
                throw ValidationException::withMessages([
                    'headcount' => "Đợt tuyển đã có {$counted} CV được duyệt — số người cần tuyển không được nhỏ hơn {$counted}.",
                ]);
            }

            $locked->fill($this->normalizeOpening($data))->save();
            if ($locked->wasChanged('position_id')) {
                $this->applyPositionQuestionSets($locked);
            }
            $this->syncOpeningStatus($locked);

            return $locked;
        });
    }

    public function closeOpening(RecruitmentOpening $opening): RecruitmentOpening
    {
        return DB::transaction(function () use ($opening) {
            $locked = $this->lockOpening($opening);
            $locked->forceFill(['status' => RecruitmentOpening::STATUS_CLOSED])->save();

            return $locked;
        });
    }

    public function reopenOpening(RecruitmentOpening $opening): RecruitmentOpening
    {
        return DB::transaction(function () use ($opening) {
            $locked = $this->lockOpening($opening);

            if ($locked->status !== RecruitmentOpening::STATUS_CLOSED) {
                throw ValidationException::withMessages(['opening' => 'Đợt tuyển này đang mở, không cần mở lại.']);
            }

            // Mở lại rồi tính lại: nếu đã đủ CV thì thành "full" chứ không "open".
            $locked->forceFill(['status' => RecruitmentOpening::STATUS_OPEN])->save();
            $this->syncOpeningStatus($locked);

            return $locked;
        });
    }

    // HR sửa thông tin ứng tuyển trước khi nhận việc: nguồn (mọi đợt), vị trí ứng tuyển + hình
    // thức mong muốn (vị trí chỉ đổi được ở đợt tuyển chung).
    public function updateApplication(RecruitmentCandidate $candidate, array $data): RecruitmentCandidate
    {
        // Đã có offer đang mở (chờ duyệt / đã gửi / đã chấp nhận) thì phòng ban, chức vụ, hợp đồng
        // đã chốt trong offer -> khóa; offer bị hủy / không duyệt / hết hạn thì sửa lại được.
        if ($candidate->status === RecruitmentCandidate::STATUS_HIRED) {
            throw ValidationException::withMessages(['candidate' => 'Ứng viên đã tuyển — không sửa thông tin ứng tuyển nữa.']);
        }
        if ($candidate->offers()->whereIn('status', RecruitmentOffer::OPEN_STATUSES)->exists()) {
            throw ValidationException::withMessages(['candidate' => 'Ứng viên đang có offer (chờ duyệt / đã gửi / đã chấp nhận) — thông tin đã chốt trong offer, không sửa thông tin ứng tuyển nữa.']);
        }
        $attributes = array_intersect_key($data, array_flip(['source', 'source_note', 'desired_contract_type']));
        if (array_key_exists('applied_position_id', $data)) {
            // Đợt theo chức vụ: vị trí ứng tuyển cố định = chức vụ của đợt.
            if (! $candidate->opening->isGeneral() && (int) $data['applied_position_id'] !== (int) $candidate->opening->position_id) {
                throw ValidationException::withMessages(['applied_position_id' => 'Chỉ đợt tuyển chung mới chọn vị trí ứng tuyển cho từng ứng viên.']);
            }
            $attributes['applied_position_id'] = $data['applied_position_id'];
        }

        $candidate->update($attributes);

        return $candidate;
    }

    public function deleteOpening(RecruitmentOpening $opening): void
    {
        if ($opening->candidates()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'opening' => 'Đợt tuyển đã có CV — hãy "Đóng đợt tuyển" thay vì xóa để giữ lịch sử.',
            ]);
        }

        $opening->delete();
    }

    /* ---------------------------------- CV ---------------------------------- */

    public function addCandidate(RecruitmentOpening $opening, array $data, UploadedFile $cvFile, User $actor): RecruitmentCandidate
    {
        $path = $cvFile->store(self::CV_DIRECTORY, self::CV_DISK);

        try {
            $candidate = DB::transaction(function () use ($opening, $data, $cvFile, $actor, $path) {
                $locked = $this->lockOpening($opening);
                $this->assertAcceptingCv($locked);

                $this->assertNotDuplicate($locked, $data['email'], $data['phone'] ?? null);

                return RecruitmentCandidate::create([
                    'recruitment_opening_id' => $locked->id,
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    // Đợt theo chức vụ: vị trí ứng tuyển = chức vụ của đợt.
                    'applied_position_id' => $locked->isGeneral() ? ($data['applied_position_id'] ?? null) : $locked->position_id,
                    'desired_contract_type' => $data['desired_contract_type'] ?? null,
                    'source' => $data['source'] ?? null,
                    'source_note' => $data['source_note'] ?? null,
                    'note' => $data['note'] ?? null,
                    'cv_file' => $path,
                    'cv_original_name' => mb_substr($cvFile->getClientOriginalName(), 0, 255),
                    'status' => RecruitmentCandidate::STATUS_PENDING,
                    'submitted_by' => $actor->id,
                ]);
            });
        } catch (\Throwable $e) {
            // Không lưu được bản ghi thì không để lại file mồ côi.
            Storage::disk(self::CV_DISK)->delete($path);

            throw $e;
        }

        $this->notifyApprovers($candidate, $actor);

        return $candidate;
    }

    // Chỉ Admin (recruitment.approve). Duyệt bị chặn khi đợt tuyển đã đủ suất.
    public function reviewCandidate(RecruitmentCandidate $candidate, string $status, ?string $note, User $actor): RecruitmentCandidate
    {
        $candidate = DB::transaction(function () use ($candidate, $status, $note, $actor) {
            $opening = $this->lockOpening($candidate->opening);
            $locked = $this->lockCandidate($candidate);

            if ($locked->status !== RecruitmentCandidate::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'CV này đã được xử lý trước đó.']);
            }

            if ($status === RecruitmentCandidate::STATUS_APPROVED) {
                if ($opening->status === RecruitmentOpening::STATUS_CLOSED) {
                    throw ValidationException::withMessages(['status' => 'Đợt tuyển đã đóng — không thể duyệt thêm CV.']);
                }

                if ($opening->isFullWith($this->recruitmentRepository->countedCandidates($opening))) {
                    throw ValidationException::withMessages([
                        'status' => "Đợt tuyển đã đủ {$opening->headcount} CV được duyệt — chỉ có thể từ chối CV này.",
                    ]);
                }
            }

            $locked->forceFill([
                'status' => $status,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->syncOpeningStatus($opening);

            return $locked;
        });

        $this->notifySubmitterOfReview($candidate, $actor);

        if ($candidate->status === RecruitmentCandidate::STATUS_REJECTED) {
            Mail::to($candidate->email)->queue(new CandidateReviewRejectedMail($candidate->load('opening')));
        }

        return $candidate;
    }

    // Duyệt/từ chối nhiều CV: xử lý LẦN LƯỢT theo đúng thứ tự gửi lên, mỗi CV là 1
    // transaction riêng qua reviewCandidate() (đủ quy tắc khóa + giới hạn suất). CV nào
    // lỗi (vd đủ suất, đã xử lý) chỉ ghi vào `failed`, các CV còn lại vẫn chạy tiếp.
    /** @return array{done: int, failed: array<int, array{id: int, full_name: string, message: string}>} */
    public function bulkReview(array $candidateIds, string $status, ?string $note, User $actor): array
    {
        return $this->runEach($candidateIds, fn (RecruitmentCandidate $candidate) => $this->reviewCandidate($candidate, $status, $note, $actor));
    }

    // Hẹn phỏng vấn theo khung giờ: ứng viên thứ i lúc start_at + i × slot_minutes.
    /** @return array{done: int, failed: array<int, array{id: int, full_name: string, message: string}>} */
    public function bulkScheduleInterviews(array $candidateIds, array $data, User $actor): array
    {
        $start = Carbon::parse($data['start_at']);
        $slot = (int) $data['slot_minutes'];
        $index = 0;

        return $this->runEach($candidateIds, function (RecruitmentCandidate $candidate) use ($data, $actor, $start, $slot, &$index) {
            // Hẹn hàng loạt chỉ cho CV chưa có lịch — đã có lịch thì "Dời lịch" từng người (tránh âm
            // thầm dời lịch + gửi lại email mời).
            if ($candidate->status !== RecruitmentCandidate::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'candidate_ids' => $candidate->status === RecruitmentCandidate::STATUS_INTERVIEW_SCHEDULED
                        ? 'Đã có lịch phỏng vấn — dùng "Dời lịch" cho từng ứng viên.'
                        : 'Chỉ hẹn được CV đã duyệt và chưa có lịch phỏng vấn.',
                ]);
            }
            // Khung giờ trùng lịch khác của người phỏng vấn -> tự nhảy sang khung trống kế tiếp.
            $interviewerId = $data['interviewer_id'] ?? null;
            for ($tries = 0; $tries < 48 && $this->interviewerConflict($interviewerId, $start->copy()->addMinutes($slot * $index), $slot); $tries++) {
                $index++;
            }
            $this->scheduleInterview($candidate, $data + [
                'scheduled_at' => $start->copy()->addMinutes($slot * $index)->toDateTimeString(),
                'duration_minutes' => $slot,
            ], $actor);
            // Chỉ ứng viên hẹn THÀNH CÔNG mới chiếm 1 khung giờ — không để trống giờ vì CV lỗi.
            $index++;
        });
    }

    private function runEach(array $candidateIds, callable $action): array
    {
        $done = 0;
        $failed = [];
        $candidates = RecruitmentCandidate::whereIn('id', $candidateIds)->get()->keyBy('id');

        foreach ($candidateIds as $id) {
            $candidate = $candidates->get((int) $id);
            if ($candidate === null) {
                continue;
            }

            try {
                $action($candidate);
                $done++;
            } catch (ValidationException $e) {
                $failed[] = [
                    'id' => $candidate->id,
                    'full_name' => $candidate->full_name,
                    'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
                ];
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    // Xóa CV tải nhầm — chỉ khi chưa được duyệt (chờ duyệt hoặc đã bị từ chối).
    public function deleteCandidate(RecruitmentCandidate $candidate): void
    {
        DB::transaction(function () use ($candidate) {
            $opening = $this->lockOpening($candidate->opening);
            $locked = $this->lockCandidate($candidate);

            if (! in_array($locked->status, [RecruitmentCandidate::STATUS_PENDING, RecruitmentCandidate::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages([
                    'candidate' => 'Chỉ xóa được CV đang chờ duyệt hoặc đã bị từ chối.',
                ]);
            }

            $locked->delete();
            $this->syncOpeningStatus($opening);
        });
    }

    /* ------------------------------ Phỏng vấn ------------------------------ */

    // Hẹn phỏng vấn cho CV đã duyệt; gọi lại khi đã có lịch = dời lịch (sửa lịch cũ).
    public function scheduleInterview(RecruitmentCandidate $candidate, array $data, User $actor): RecruitmentInterview
    {
        [$interview, $rescheduled] = DB::transaction(function () use ($candidate, $data, $actor) {
            $locked = $this->lockCandidate($candidate);

            if (! in_array($locked->status, [RecruitmentCandidate::STATUS_APPROVED, RecruitmentCandidate::STATUS_INTERVIEW_SCHEDULED], true)) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'Chỉ hẹn phỏng vấn được cho CV đã được Admin duyệt và chưa có kết quả.',
                ]);
            }

            $values = [
                'scheduled_at' => Carbon::parse($data['scheduled_at']),
                'duration_minutes' => (int) ($data['duration_minutes'] ?? 60),
                'format' => $data['format'],
                'interviewer_id' => $data['interviewer_id'] ?? null,
            ] + $this->interviewLocation($data);

            $current = $locked->interviews()->where('status', RecruitmentInterview::STATUS_SCHEDULED)->first();

            // Người phỏng vấn không được có 2 buổi chồng giờ (dời lịch thì bỏ qua chính buổi đang dời).
            $conflict = $this->interviewerConflict($values['interviewer_id'], $values['scheduled_at'], $values['duration_minutes'], $current?->id, lock: true);
            if ($conflict) {
                throw ValidationException::withMessages(['scheduled_at' => $this->conflictMessage($conflict)]);
            }

            if ($current) {
                // Dời lịch = lịch mới -> phản hồi của ứng viên về "chờ xác nhận" lại.
                $current->fill($values + [
                    'confirmation_status' => RecruitmentInterview::CONFIRMATION_PENDING,
                    'confirmation_note' => null,
                    'confirmed_by' => null,
                    'confirmed_at' => null,
                ])->save();
                $interview = $current;
            } else {
                $interview = $locked->interviews()->create($values + [
                    'status' => RecruitmentInterview::STATUS_SCHEDULED,
                    'created_by' => $actor->id,
                ]);
            }

            $locked->forceFill(['status' => RecruitmentCandidate::STATUS_INTERVIEW_SCHEDULED])->save();

            return [$interview, $current !== null];
        });

        $interview->load('candidate.opening', 'interviewer');
        Mail::to($interview->candidate->email)->queue(new CandidateInterviewInvitationMail($interview, $rescheduled));
        $this->notifyInterviewer($interview, $actor);

        return $interview;
    }

    /**
     * Buổi PV đang hẹn của cùng người phỏng vấn mà chồng giờ với [start, start + duration).
     * Không chọn người phỏng vấn thì không kiểm tra.
     */
    public function interviewerConflict(?int $interviewerId, Carbon $start, int $duration, ?int $ignoreId = null, bool $lock = false): ?RecruitmentInterview
    {
        if (! $interviewerId) {
            return null;
        }

        $end = $start->copy()->addMinutes($duration);
        // Lọc thô theo khoảng rộng (buổi dài nhất 8 tiếng), so chồng giờ chính xác bằng PHP.
        $query = RecruitmentInterview::with('candidate:id,full_name')
            ->where('interviewer_id', $interviewerId)
            ->where('status', RecruitmentInterview::STATUS_SCHEDULED)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereBetween('scheduled_at', [$start->copy()->subHours(8), $end]);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->first(fn (RecruitmentInterview $other) => $other->scheduled_at->lt($end) && $start->lt($other->endsAt()));
    }

    private function conflictMessage(RecruitmentInterview $conflict): string
    {
        $conflict->loadMissing('interviewer:id,full_name');

        return 'Người phỏng vấn '.($conflict->interviewer?->full_name ?? '').' đã có lịch phỏng vấn '
            .($conflict->candidate?->full_name ?? 'ứng viên khác').' lúc '.$conflict->scheduled_at->format('H:i')
            .'–'.$conflict->endsAt()->format('H:i d/m/Y').' — chọn giờ khác hoặc người phỏng vấn khác.';
    }

    // Trực tiếp: ghép "chi tiết, Xã/Phường, Tỉnh/Thành" thành location (dùng cho email,
    // hiển thị) + giữ mã tỉnh/xã để dời lịch điền lại được. Trực tuyến: location = link.
    private function interviewLocation(array $data): array
    {
        if ($data['format'] === RecruitmentInterview::FORMAT_ONLINE) {
            return ['location' => $data['location'], 'province_code' => null, 'commune_code' => null, 'address_detail' => null];
        }

        $commune = Commune::where('code', $data['commune_code'])->value('name');
        $province = Province::where('code', $data['province_code'])->value('name');

        return [
            'location' => implode(', ', array_filter([trim($data['address_detail']), $commune, $province])),
            'province_code' => (int) $data['province_code'],
            'commune_code' => (int) $data['commune_code'],
            'address_detail' => trim($data['address_detail']),
        ];
    }

    // Ghi kết quả sau giờ phỏng vấn. Trượt thì trả lại suất cho đợt tuyển.
    // $viaScorecard: gọi từ InterviewScorecardService khi nộp phiếu chấm. Đợt tuyển đã gắn bộ
    // câu hỏi thì CHỈ ra kết quả qua phiếu chấm (kết quả luôn khớp điểm), không ghi tay.
    public function recordResult(RecruitmentCandidate $candidate, string $result, ?string $note, bool $viaScorecard = false): RecruitmentCandidate
    {
        $candidate = DB::transaction(function () use ($candidate, $result, $note, $viaScorecard) {
            $opening = $this->lockOpening($candidate->opening);

            if (! $viaScorecard && $candidate->effectiveQuestionSets()[0]->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'result' => 'Đợt tuyển này chấm theo bộ câu hỏi — vào "Chấm điểm phỏng vấn" để ra kết quả.',
                ]);
            }

            $locked = $this->lockCandidate($candidate);
            $interview = $locked->interviews()->where('status', RecruitmentInterview::STATUS_SCHEDULED)->first();

            if ($locked->status !== RecruitmentCandidate::STATUS_INTERVIEW_SCHEDULED || $interview === null) {
                throw ValidationException::withMessages(['result' => 'Ứng viên chưa có lịch phỏng vấn hoặc đã có kết quả.']);
            }

            // Ứng viên phải được HR xác nhận ĐỒNG Ý tham gia (trang Lịch phỏng vấn) mới ghi kết
            // quả — tránh ghi Đạt/Không đạt cho buổi ứng viên chưa trả lời hoặc xin đổi lịch.
            if ($interview->confirmation_status !== RecruitmentInterview::CONFIRMATION_CONFIRMED) {
                throw ValidationException::withMessages([
                    'result' => 'HR chưa xác nhận ứng viên đồng ý tham gia phỏng vấn — ghi nhận phản hồi của ứng viên ở trang "Lịch phỏng vấn" trước.',
                ]);
            }

            if ($interview->scheduled_at->isFuture()) {
                throw ValidationException::withMessages([
                    'result' => 'Chưa tới giờ phỏng vấn ('.$interview->scheduled_at->format('H:i d/m/Y').') — chưa ghi được kết quả.',
                ]);
            }

            $interview->forceFill([
                'status' => RecruitmentInterview::STATUS_COMPLETED,
                'result' => $result,
                'result_note' => $note,
            ])->save();

            $locked->forceFill([
                'status' => $result === 'passed' ? RecruitmentCandidate::STATUS_PASSED : RecruitmentCandidate::STATUS_FAILED,
            ])->save();

            $this->syncOpeningStatus($opening);

            return $locked;
        });

        Mail::to($candidate->email)->queue(new CandidateInterviewResultMail($candidate->load('opening')));

        return $candidate;
    }

    /* ------------------------------ Nhận việc ------------------------------ */

    // Gọi BÊN TRONG transaction tạo nhân viên (EmployeeService::create()) — tạo nhân
    // viên và đánh dấu ứng viên "đã nhận việc" cùng thành công hoặc cùng hủy.
    public function markHired(int $candidateId, Employee $employee): void
    {
        $candidate = RecruitmentCandidate::whereKey($candidateId)->lockForUpdate()->first();

        if ($candidate === null || $candidate->status !== RecruitmentCandidate::STATUS_PASSED) {
            throw ValidationException::withMessages([
                'candidate_id' => 'Ứng viên chưa đạt phỏng vấn hoặc đã nhận việc trước đó.',
            ]);
        }

        // Bắt buộc có thư mời nhận việc đã được ứng viên chấp nhận (RecruitmentOfferService).
        if (! $candidate->offers()->where('status', RecruitmentOffer::STATUS_ACCEPTED)->exists()) {
            throw ValidationException::withMessages([
                'candidate_id' => 'Ứng viên chưa chấp nhận thư mời nhận việc (offer).',
            ]);
        }

        $candidate->forceFill([
            'status' => RecruitmentCandidate::STATUS_HIRED,
            'hired_employee_id' => $employee->id,
        ])->save();
    }

    /* -------------------------------- Nội bộ -------------------------------- */

    private function lockOpening(RecruitmentOpening $opening): RecruitmentOpening
    {
        return RecruitmentOpening::whereKey($opening->id)->lockForUpdate()->firstOrFail();
    }

    private function lockCandidate(RecruitmentCandidate $candidate): RecruitmentCandidate
    {
        return RecruitmentCandidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
    }

    private function assertAcceptingCv(RecruitmentOpening $opening): void
    {
        if ($opening->status === RecruitmentOpening::STATUS_CLOSED) {
            throw ValidationException::withMessages(['opening' => 'Đợt tuyển đã đóng — không nhận thêm CV.']);
        }

        if ($opening->status === RecruitmentOpening::STATUS_FULL
            || $opening->isFullWith($this->recruitmentRepository->countedCandidates($opening))) {
            throw ValidationException::withMessages([
                'opening' => "Đợt tuyển đã đủ {$opening->headcount} CV được duyệt — ngưng nhận CV mới.",
            ]);
        }

        if ($opening->isPastDeadline()) {
            throw ValidationException::withMessages([
                'opening' => 'Đã quá hạn nộp CV ('.$opening->deadline->format('d/m/Y').').',
            ]);
        }
    }

    // Chặn trùng ngay lúc nhận CV, không để tới bước "Nhận việc" mới vấp: email/SĐT đã
    // thuộc 1 hồ sơ nhân viên (unique ở employees) hoặc đã có CV trong cùng đợt tuyển.
    // Gom mọi lỗi để hiện cùng lúc dưới đúng từng ô.
    private function assertNotDuplicate(RecruitmentOpening $opening, string $email, ?string $phone): void
    {
        $errors = array_filter([
            'email' => $this->duplicateMessage($opening, 'email', $email),
            'phone' => $phone !== null && $phone !== '' ? $this->duplicateMessage($opening, 'phone', $phone) : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    // Câu báo trùng của 1 ô (email | phone), null nếu không trùng. Dùng CHUNG cho lúc
    // lưu CV và API kiểm tra khi rời ô (checkDuplicate) — 2 nơi luôn báo giống nhau.
    public function duplicateMessage(RecruitmentOpening $opening, string $field, string $value): ?string
    {
        if ($field === 'email') {
            if ($employee = $this->recruitmentRepository->employeeWithEmail($value)) {
                return 'Email này đã thuộc hồ sơ nhân viên '.$this->employeeLabel($employee).'.';
            }

            return $this->recruitmentRepository->emailAlreadySubmitted($opening, $value)
                ? 'Ứng viên với email này đã có CV trong đợt tuyển.'
                : null;
        }

        if ($employee = $this->recruitmentRepository->employeeWithPhone($value)) {
            return 'Số điện thoại này đã thuộc hồ sơ nhân viên '.$this->employeeLabel($employee).'.';
        }

        return $this->recruitmentRepository->phoneAlreadySubmitted($opening, $value)
            ? 'Ứng viên với số điện thoại này đã có CV trong đợt tuyển.'
            : null;
    }

    private function employeeLabel(Employee $employee): string
    {
        return "{$employee->code} – {$employee->full_name}".($employee->deleted_at ? ' (đã xóa)' : '');
    }

    // open <-> full theo số CV chiếm suất; đợt đã đóng tay thì giữ nguyên. Public để
    // RecruitmentOfferService dùng lại khi ứng viên từ chối offer (trả lại suất).
    public function syncOpeningStatus(RecruitmentOpening $opening): void
    {
        if ($opening->status === RecruitmentOpening::STATUS_CLOSED) {
            return;
        }

        $status = $opening->isFullWith($this->recruitmentRepository->countedCandidates($opening))
            ? RecruitmentOpening::STATUS_FULL
            : RecruitmentOpening::STATUS_OPEN;

        if ($opening->status !== $status) {
            $opening->forceFill(['status' => $status])->save();
        }
    }

    private function notifyApprovers(RecruitmentCandidate $candidate, User $actor): void
    {
        $candidate->loadMissing('opening');
        $data = ['recruitment_opening_id' => $candidate->recruitment_opening_id, 'candidate_id' => $candidate->id];

        foreach (User::withPermission('recruitment.approve')->where('id', '!=', $actor->id)->get() as $approver) {
            $this->notificationService->send(
                $approver,
                'recruitment.cv_pending',
                'CV mới cần duyệt',
                "{$actor->user_name} vừa gửi CV của {$candidate->full_name} cho đợt tuyển \"{$candidate->opening->title}\".",
                $data,
            );
        }
    }

    private function notifySubmitterOfReview(RecruitmentCandidate $candidate, User $actor): void
    {
        $submitter = $candidate->submitted_by ? User::find($candidate->submitted_by) : null;

        if ($submitter === null || $submitter->id === $actor->id) {
            return;
        }

        $approved = $candidate->status === RecruitmentCandidate::STATUS_APPROVED;
        $this->notificationService->send(
            $submitter,
            'recruitment.cv_reviewed',
            $approved ? 'CV đã được duyệt' : 'CV bị từ chối',
            $approved
                ? "CV của {$candidate->full_name} đã được duyệt — có thể hẹn phỏng vấn."
                : "CV của {$candidate->full_name} bị từ chối: {$candidate->review_note}",
            ['recruitment_opening_id' => $candidate->recruitment_opening_id, 'candidate_id' => $candidate->id],
        );
    }

    private function notifyInterviewer(RecruitmentInterview $interview, User $actor): void
    {
        $user = $interview->interviewer?->user_id ? User::find($interview->interviewer->user_id) : null;

        if ($user === null || $user->id === $actor->id) {
            return;
        }

        $this->notificationService->send(
            $user,
            'recruitment.interview_assigned',
            'Bạn được phân công phỏng vấn',
            "Phỏng vấn {$interview->candidate->full_name} lúc ".$interview->scheduled_at->format('H:i d/m/Y').'.',
            ['recruitment_opening_id' => $interview->candidate->recruitment_opening_id, 'candidate_id' => $interview->candidate->id],
        );
    }
}
