<?php

namespace App\Services;

use App\Mail\CandidateOfferMail;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOpening;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

// Thư mời nhận việc (Offer) — ngoài tài liệu gốc, người dùng duyệt mở rộng. Quy tắc:
//  - Chỉ ứng viên ĐẠT phỏng vấn mới có offer; mỗi ứng viên tối đa 1 offer đang mở
//    (chờ duyệt / đã gửi / đã chấp nhận). HR (recruitment.manage) soạn.
//  - Admin (recruitment.approve) duyệt -> mới gửi email cho ứng viên; không duyệt thì
//    HR soạn lại offer mới.
//  - Email KHÔNG có link trả lời (2026-10-13, ứng viên ngại bấm link vì sợ lừa đảo): ghi thông
//    tin người liên hệ (mặc định HR soạn offer) + EMAIL HỆ THỐNG CÔNG TY (config/recruitment.php,
//    không dùng email cá nhân), Reply-To về email đó. Ứng viên báo
//    qua điện thoại/email -> HR "Ghi nhận phản hồi" (recordResponse) -> Admin được thông báo.
//    Quá hạn trả lời mà HR chưa ghi nhận -> "hết hạn".
//  - Từ chối / hết hạn -> ứng viên "Từ chối offer" và TRẢ LẠI SUẤT cho đợt tuyển.
//  - HR rút offer (chờ duyệt / đã gửi) -> ứng viên vẫn "Đạt", soạn offer mới được.
//  - Nhận việc (RecruitmentService::markHired) chỉ khi offer đã được chấp nhận.
class RecruitmentOfferService
{
    public function __construct(
        private readonly RecruitmentService $recruitmentService,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function create(RecruitmentCandidate $candidate, array $data, User $actor): RecruitmentOffer
    {
        $offer = DB::transaction(function () use ($candidate, $data, $actor) {
            $locked = RecruitmentCandidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RecruitmentCandidate::STATUS_PASSED) {
                throw ValidationException::withMessages(['candidate' => 'Chỉ gửi offer cho ứng viên đã đạt phỏng vấn.']);
            }

            if ($locked->offers()->whereIn('status', RecruitmentOffer::OPEN_STATUSES)->exists()) {
                throw ValidationException::withMessages(['candidate' => 'Ứng viên đang có 1 offer chờ duyệt, đã gửi hoặc đã chấp nhận.']);
            }

            return $locked->offers()->create([
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'] ?? null,
                'contract_type' => $data['contract_type'],
                'salary' => $data['salary'],
                'start_date' => $data['start_date'],
                'response_deadline' => $data['response_deadline'],
                'message' => $data['message'] ?? null,
                'contact_name' => $data['contact_name'] ?? ($actor->employee?->full_name ?? $actor->user_name),
                'contact_phone' => $data['contact_phone'] ?? $actor->employee?->phone,
                // Email liên hệ LUÔN là email hệ thống công ty (config/recruitment.php), không phải email cá nhân.
                'contact_email' => self::companyContactEmail(),
                'status' => RecruitmentOffer::STATUS_PENDING_APPROVAL,
                'created_by' => $actor->id,
            ]);
        });

        $this->notifyApprovers($offer->load('candidate.opening'), $actor);

        return $offer;
    }

    public function review(RecruitmentOffer $offer, bool $approve, ?string $note, User $actor): RecruitmentOffer
    {
        $offer = DB::transaction(function () use ($offer, $approve, $note, $actor) {
            $locked = RecruitmentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RecruitmentOffer::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages(['status' => 'Offer này không ở trạng thái chờ duyệt.']);
            }

            if ($approve && $locked->isPastDeadline()) {
                throw ValidationException::withMessages(['status' => 'Hạn trả lời của offer đã qua — HR cần rút và soạn lại offer.']);
            }

            $attributes = [
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
                'status' => $approve ? RecruitmentOffer::STATUS_SENT : RecruitmentOffer::STATUS_REJECTED,
            ];

            if ($approve) {
                $attributes['sent_at'] = now();
            }

            $locked->update($attributes);

            return $locked;
        });

        $offer->load('candidate.opening');

        if ($approve) {
            Mail::to($offer->candidate->email)->queue(new CandidateOfferMail($offer));
        }

        $this->notifyCreator($offer, $approve
            ? ['Offer đã được duyệt và gửi', "Offer cho {$offer->candidate->full_name} đã được duyệt và gửi email cho ứng viên."]
            : ['Offer chưa được duyệt', "Offer cho {$offer->candidate->full_name} chưa được duyệt.".($note ? " Lý do: {$note}" : '')]);

        return $offer;
    }

    // Sửa offer khi còn CHỜ DUYỆT — Admin sửa ngay trong lúc xem duyệt, hoặc HR sửa offer mình
    // soạn. Admin sửa offer của HR thì HR được báo các thay đổi.
    public function update(RecruitmentOffer $offer, array $data, User $actor): RecruitmentOffer
    {
        [$offer, $changes] = DB::transaction(function () use ($offer, $data) {
            $locked = RecruitmentOffer::with('department', 'position')->whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RecruitmentOffer::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages(['status' => 'Chỉ sửa được offer đang chờ duyệt.']);
            }

            $before = $this->summary($locked);
            $locked->update([
                'department_id' => $data['department_id'],
                'position_id' => $data['position_id'] ?? null,
                'contract_type' => $data['contract_type'],
                'salary' => $data['salary'],
                'start_date' => $data['start_date'],
                'response_deadline' => $data['response_deadline'],
                'message' => $data['message'] ?? null,
                'contact_name' => $data['contact_name'] ?? $locked->contact_name,
                'contact_phone' => $data['contact_phone'] ?? $locked->contact_phone,
                'contact_email' => self::companyContactEmail(),
            ]);
            $after = $this->summary($locked->fresh(['department', 'position']));

            $changes = [];
            foreach ($after as $label => $value) {
                if (($before[$label] ?? null) !== $value) {
                    $changes[] = "{$label}: ".($before[$label] ?: '—')." → ".($value ?: '—');
                }
            }

            return [$locked, $changes];
        });

        $offer->load('candidate.opening');
        $creator = $offer->created_by ? User::find($offer->created_by) : null;
        if ($changes !== [] && $creator && $creator->id !== $actor->id) {
            $this->notificationService->send(
                $creator,
                'recruitment.offer_reviewed',
                'Offer đã được chỉnh sửa',
                "{$actor->user_name} đã sửa offer cho {$offer->candidate->full_name}: ".implode('; ', $changes).'.',
                $this->data($offer) + ['status' => 'approved'],
            );
        }

        return $offer;
    }

    // Các thông tin chính của offer dạng chữ — để so sánh trước/sau khi sửa.
    private function summary(RecruitmentOffer $offer): array
    {
        $contracts = ['thuc_tap' => 'Thực tập', 'thu_viec' => 'Thử việc', 'chinh_thuc' => 'Chính thức'];

        return [
            'Phòng ban' => $offer->department?->name,
            'Chức vụ' => $offer->position?->name,
            'Loại hợp đồng' => $contracts[$offer->contract_type] ?? $offer->contract_type,
            'Lương' => number_format((float) $offer->salary, 0, ',', '.').' ₫',
            'Ngày bắt đầu' => $offer->start_date?->format('d/m/Y'),
            'Hạn trả lời' => $offer->response_deadline?->format('d/m/Y'),
            'Lời nhắn' => $offer->message,
            'Người liên hệ' => trim(($offer->contact_name ?? '').' '.($offer->contact_phone ?? '')),
        ];
    }

    public function withdraw(RecruitmentOffer $offer): RecruitmentOffer
    {
        return DB::transaction(function () use ($offer) {
            $locked = RecruitmentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [RecruitmentOffer::STATUS_PENDING_APPROVAL, RecruitmentOffer::STATUS_SENT], true)) {
                throw ValidationException::withMessages(['status' => 'Chỉ hủy được offer đang chờ duyệt hoặc đã gửi mà ứng viên chưa trả lời.']);
            }

            $locked->update(['status' => RecruitmentOffer::STATUS_WITHDRAWN]);

            return $locked;
        });
    }

    public static function companyContactEmail(): ?string
    {
        return config('recruitment.offer_contact_email') ?: null;
    }

    /** Giá trị điền sẵn cho form soạn offer: người liên hệ = HR đang soạn, email = email công ty. */
    public function defaults(User $actor): array
    {
        $actor->loadMissing('employee');

        return [
            'contact_name' => $actor->employee?->full_name ?? $actor->user_name,
            'contact_phone' => $actor->employee?->phone,
            'contact_email' => self::companyContactEmail(),
        ];
    }

    // HR ghi nhận phản hồi ứng viên báo qua điện thoại / email (không còn link tự trả lời).
    // Chấp nhận -> làm thủ tục Nhận việc; từ chối -> trả lại suất. Admin được thông báo.
    public function recordResponse(RecruitmentOffer $offer, bool $accept, ?string $note, User $actor): RecruitmentOffer
    {
        $offer = DB::transaction(function () use ($offer, $accept, $note, $actor) {
            $locked = RecruitmentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RecruitmentOffer::STATUS_SENT) {
                throw ValidationException::withMessages(['decision' => $this->closedMessage($locked)]);
            }

            $locked->update([
                'status' => $accept ? RecruitmentOffer::STATUS_ACCEPTED : RecruitmentOffer::STATUS_DECLINED,
                'responded_at' => now(),
                'response_note' => $note,
                'response_recorded_by' => $actor->id,
            ]);

            if (! $accept) {
                $this->releaseSlot($locked);
            }

            return $locked;
        });

        $offer->load('candidate.opening');
        $name = $offer->candidate->full_name;
        $this->notifyUsers(
            User::withPermission('recruitment.approve')->where('id', '!=', $actor->id)->get(),
            $offer,
            $accept
                ? ['Ứng viên đã chấp nhận offer', "{$actor->user_name} ghi nhận: {$name} đã chấp nhận thư mời nhận việc.".($note ? " Ghi chú: {$note}" : '')]
                : ['Ứng viên từ chối offer', "{$actor->user_name} ghi nhận: {$name} đã từ chối thư mời nhận việc.".($note ? " Lý do: {$note}" : '')],
        );

        return $offer;
    }

    // Offer đã gửi mà quá hạn trả lời -> hết hạn + trả lại suất (job chạy mỗi giờ).
    public function expireOverdue(): int
    {
        $count = 0;
        RecruitmentOffer::where('status', RecruitmentOffer::STATUS_SENT)
            ->whereDate('response_deadline', '<', today())
            ->get()
            ->each(function (RecruitmentOffer $offer) use (&$count) {
                $this->expire($offer);
                $count++;
            });

        return $count;
    }

    private function closedMessage(RecruitmentOffer $offer): string
    {
        return match ($offer->status) {
            RecruitmentOffer::STATUS_ACCEPTED => 'Ứng viên đã được ghi nhận chấp nhận offer này trước đó.',
            RecruitmentOffer::STATUS_DECLINED => 'Ứng viên đã được ghi nhận từ chối offer này trước đó.',
            RecruitmentOffer::STATUS_EXPIRED => 'Offer đã hết hạn trả lời — soạn offer mới nếu ứng viên vẫn muốn nhận việc.',
            RecruitmentOffer::STATUS_PENDING_APPROVAL => 'Offer chưa được Admin duyệt gửi cho ứng viên.',
            default => 'Offer này không còn hiệu lực.',
        };
    }

    private function expire(RecruitmentOffer $offer): void
    {
        $expired = DB::transaction(function () use ($offer) {
            $locked = RecruitmentOffer::whereKey($offer->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== RecruitmentOffer::STATUS_SENT) {
                return null;
            }

            $locked->update(['status' => RecruitmentOffer::STATUS_EXPIRED]);
            $this->releaseSlot($locked);

            return $locked;
        });

        if ($expired) {
            $expired->load('candidate.opening');
            $this->notifyUsers(
                User::withPermission('recruitment.manage')->get()->merge(User::withPermission('recruitment.approve')->get())->unique('id'),
                $expired,
                ['Offer đã hết hạn', "{$expired->candidate->full_name} không phản hồi thư mời nhận việc trước hạn — đã trả lại suất cho đợt tuyển."],
            );
        }
    }

    // Ứng viên không nhận việc -> "Từ chối offer", đợt tuyển mở lại suất (nếu chưa đóng tay).
    private function releaseSlot(RecruitmentOffer $offer): void
    {
        $candidate = RecruitmentCandidate::whereKey($offer->recruitment_candidate_id)->lockForUpdate()->firstOrFail();
        $opening = RecruitmentOpening::whereKey($candidate->recruitment_opening_id)->lockForUpdate()->firstOrFail();

        if ($candidate->status === RecruitmentCandidate::STATUS_PASSED) {
            $candidate->forceFill(['status' => RecruitmentCandidate::STATUS_OFFER_DECLINED])->save();
        }

        $this->recruitmentService->syncOpeningStatus($opening);
    }

    private function data(RecruitmentOffer $offer): array
    {
        return [
            'recruitment_opening_id' => $offer->candidate->recruitment_opening_id,
            'candidate_id' => $offer->recruitment_candidate_id,
            'offer_id' => $offer->id,
        ];
    }

    private function notifyApprovers(RecruitmentOffer $offer, User $actor): void
    {
        foreach (User::withPermission('recruitment.approve')->where('id', '!=', $actor->id)->get() as $approver) {
            $this->notificationService->send(
                $approver,
                'recruitment.offer_pending',
                'Offer cần duyệt',
                "{$actor->user_name} vừa soạn thư mời nhận việc cho {$offer->candidate->full_name} (đợt tuyển \"{$offer->candidate->opening->title}\").",
                $this->data($offer),
            );
        }
    }

    private function notifyCreator(RecruitmentOffer $offer, array $text): void
    {
        $creator = $offer->created_by ? User::find($offer->created_by) : null;
        if ($creator && $creator->id !== $offer->reviewed_by) {
            $this->notificationService->send($creator, 'recruitment.offer_reviewed', $text[0], $text[1], $this->data($offer) + ['status' => $offer->status === RecruitmentOffer::STATUS_SENT ? 'approved' : 'rejected']);
        }
    }

    private function notifyUsers($users, RecruitmentOffer $offer, array $text): void
    {
        foreach ($users as $user) {
            $this->notificationService->send($user, 'recruitment.offer_responded', $text[0], $text[1], $this->data($offer) + [
                'status' => $offer->status === RecruitmentOffer::STATUS_ACCEPTED ? 'approved' : 'rejected',
            ]);
        }
    }
}
