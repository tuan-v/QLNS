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
//    headcount. Đủ thì đợt tuyển tự "full" và ngưng nhận CV; CV bị từ chối hoặc trượt
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
    ) {
    }

    /* ------------------------------- Đợt tuyển ------------------------------- */

    public function listOpenings(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->recruitmentRepository->paginateOpenings($filters, $perPage);
    }

    public function showOpening(RecruitmentOpening $opening): RecruitmentOpening
    {
        return $this->recruitmentRepository->loadOpeningDetail($opening);
    }

    public function createOpening(array $data, User $actor): RecruitmentOpening
    {
        return DB::transaction(fn () => RecruitmentOpening::create($data + [
            'status' => RecruitmentOpening::STATUS_OPEN,
            'created_by' => $actor->id,
        ]));
    }

    public function updateOpening(RecruitmentOpening $opening, array $data): RecruitmentOpening
    {
        return DB::transaction(function () use ($opening, $data) {
            $locked = $this->lockOpening($opening);
            $counted = $this->recruitmentRepository->countedCandidates($locked);

            if ((int) $data['headcount'] < $counted) {
                throw ValidationException::withMessages([
                    'headcount' => "Đợt tuyển đã có {$counted} CV được duyệt — số người cần tuyển không được nhỏ hơn {$counted}.",
                ]);
            }

            $locked->fill($data)->save();
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

                if ($this->recruitmentRepository->countedCandidates($opening) >= $opening->headcount) {
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
            $this->scheduleInterview($candidate, $data + [
                'scheduled_at' => $start->copy()->addMinutes($slot * $index)->toDateTimeString(),
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
                'format' => $data['format'],
                'interviewer_id' => $data['interviewer_id'] ?? null,
            ] + $this->interviewLocation($data);

            $current = $locked->interviews()->where('status', RecruitmentInterview::STATUS_SCHEDULED)->first();

            if ($current) {
                $current->fill($values)->save();
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
    public function recordResult(RecruitmentCandidate $candidate, string $result, ?string $note): RecruitmentCandidate
    {
        $candidate = DB::transaction(function () use ($candidate, $result, $note) {
            $opening = $this->lockOpening($candidate->opening);
            $locked = $this->lockCandidate($candidate);
            $interview = $locked->interviews()->where('status', RecruitmentInterview::STATUS_SCHEDULED)->first();

            if ($locked->status !== RecruitmentCandidate::STATUS_INTERVIEW_SCHEDULED || $interview === null) {
                throw ValidationException::withMessages(['result' => 'Ứng viên chưa có lịch phỏng vấn hoặc đã có kết quả.']);
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
            || $this->recruitmentRepository->countedCandidates($opening) >= $opening->headcount) {
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

        $status = $this->recruitmentRepository->countedCandidates($opening) >= $opening->headcount
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
