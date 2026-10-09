<?php

namespace App\Services;

use App\Mail\CandidateOfferMail;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOpening;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Thư mời nhận việc (Offer) — ngoài tài liệu gốc, người dùng duyệt mở rộng. Quy tắc:
//  - Chỉ ứng viên ĐẠT phỏng vấn mới có offer; mỗi ứng viên tối đa 1 offer đang mở
//    (chờ duyệt / đã gửi / đã chấp nhận). HR (recruitment.manage) soạn.
//  - Admin (recruitment.approve) duyệt -> mới gửi email cho ứng viên; không duyệt thì
//    HR soạn lại offer mới.
//  - Ứng viên trả lời bằng link trong email: mã ngẫu nhiên 48 ký tự, DB chỉ lưu SHA-256,
//    dùng được tới hết ngày hạn trả lời, trả lời 1 lần. Quá hạn -> "hết hạn".
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
                'contract_type' => $data['contract_type'],
                'salary' => $data['salary'],
                'start_date' => $data['start_date'],
                'response_deadline' => $data['response_deadline'],
                'message' => $data['message'] ?? null,
                'status' => RecruitmentOffer::STATUS_PENDING_APPROVAL,
                'created_by' => $actor->id,
            ]);
        });

        $this->notifyApprovers($offer->load('candidate.opening'), $actor);

        return $offer;
    }

    public function review(RecruitmentOffer $offer, bool $approve, ?string $note, User $actor): RecruitmentOffer
    {
        $plainToken = null;

        $offer = DB::transaction(function () use ($offer, $approve, $note, $actor, &$plainToken) {
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
                $plainToken = Str::random(48);
                $attributes['token_hash'] = hash('sha256', $plainToken);
                $attributes['sent_at'] = now();
            }

            $locked->update($attributes);

            return $locked;
        });

        $offer->load('candidate.opening');

        if ($plainToken !== null) {
            Mail::to($offer->candidate->email)->queue(new CandidateOfferMail($offer, $this->responseUrl($plainToken)));
        }

        $this->notifyCreator($offer, $approve
            ? ['Offer đã được duyệt và gửi', "Offer cho {$offer->candidate->full_name} đã được duyệt và gửi email cho ứng viên."]
            : ['Offer chưa được duyệt', "Offer cho {$offer->candidate->full_name} chưa được duyệt.".($note ? " Lý do: {$note}" : '')]);

        return $offer;
    }

    public function withdraw(RecruitmentOffer $offer): RecruitmentOffer
    {
        return DB::transaction(function () use ($offer) {
            $locked = RecruitmentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [RecruitmentOffer::STATUS_PENDING_APPROVAL, RecruitmentOffer::STATUS_SENT], true)) {
                throw ValidationException::withMessages(['status' => 'Chỉ rút được offer đang chờ duyệt hoặc đã gửi mà ứng viên chưa trả lời.']);
            }

            $locked->update(['status' => RecruitmentOffer::STATUS_WITHDRAWN, 'token_hash' => null]);

            return $locked;
        });
    }

    // Trang công khai cho ứng viên: tìm offer theo mã trong link (đã gửi hoặc đã trả lời).
    public function findByToken(string $token): RecruitmentOffer
    {
        $offer = RecruitmentOffer::with('candidate.opening.department', 'candidate.opening.position')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        abort_if(! $offer, 404, 'Link không hợp lệ hoặc thư mời đã bị thu hồi.');

        if ($offer->status === RecruitmentOffer::STATUS_SENT && $offer->isPastDeadline()) {
            $this->expire($offer);
            $offer->refresh();
        }

        return $offer;
    }

    public function respond(string $token, bool $accept, ?string $note): RecruitmentOffer
    {
        $offer = $this->findByToken($token);

        $offer = DB::transaction(function () use ($offer, $accept, $note) {
            $locked = RecruitmentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RecruitmentOffer::STATUS_SENT) {
                throw ValidationException::withMessages(['decision' => $this->closedMessage($locked)]);
            }

            $locked->update([
                'status' => $accept ? RecruitmentOffer::STATUS_ACCEPTED : RecruitmentOffer::STATUS_DECLINED,
                'responded_at' => now(),
                'response_note' => $accept ? null : $note,
            ]);

            if (! $accept) {
                $this->releaseSlot($locked);
            }

            return $locked;
        });

        $offer->load('candidate.opening');
        $this->notifyManagers($offer, $accept
            ? ['Ứng viên đã chấp nhận offer', "{$offer->candidate->full_name} đã chấp nhận thư mời nhận việc — có thể làm thủ tục Nhận việc."]
            : ['Ứng viên từ chối offer', "{$offer->candidate->full_name} đã từ chối thư mời nhận việc.".($note ? " Lý do: {$note}" : '')]);

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

    public function closedMessage(RecruitmentOffer $offer): string
    {
        return match ($offer->status) {
            RecruitmentOffer::STATUS_ACCEPTED => 'Bạn đã chấp nhận thư mời này trước đó.',
            RecruitmentOffer::STATUS_DECLINED => 'Bạn đã từ chối thư mời này trước đó.',
            RecruitmentOffer::STATUS_EXPIRED => 'Thư mời đã hết hạn trả lời.',
            default => 'Thư mời này không còn hiệu lực.',
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
            $this->notifyManagers($expired, ['Offer đã hết hạn', "{$expired->candidate->full_name} không trả lời thư mời nhận việc trước hạn — đã trả lại suất cho đợt tuyển."]);
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

    private function responseUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/thu-moi-nhan-viec/'.$token;
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

    private function notifyManagers(RecruitmentOffer $offer, array $text): void
    {
        foreach (User::withPermission('recruitment.manage')->get() as $user) {
            $this->notificationService->send($user, 'recruitment.offer_responded', $text[0], $text[1], $this->data($offer) + [
                'status' => $offer->status === RecruitmentOffer::STATUS_ACCEPTED ? 'approved' : 'rejected',
            ]);
        }
    }
}
