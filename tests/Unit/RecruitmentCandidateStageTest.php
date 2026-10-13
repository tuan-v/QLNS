<?php

namespace Tests\Unit;

use App\Models\RecruitmentCandidate;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Giai đoạn hiển thị thống nhất của ứng viên = mã trạng thái + offer gần nhất.
class RecruitmentCandidateStageTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'CV mới' => ['pending', null, 'screening', 'Chờ duyệt CV', null],
            'CV đã duyệt, chưa hẹn' => ['approved', null, 'awaiting_schedule', 'Chờ hẹn phỏng vấn', null],
            'Đạt, chưa offer' => ['passed', null, 'passed', 'Đạt phỏng vấn', null],
            'Đạt, offer không được duyệt' => ['passed', 'rejected', 'passed', 'Đạt phỏng vấn', null],
            'Đạt, offer đã hủy' => ['passed', 'withdrawn', 'passed', 'Đạt phỏng vấn', null],
            'Offer chờ duyệt' => ['passed', 'pending_approval', 'offering', 'Đang đề nghị (offer)', null],
            'Offer đã gửi' => ['passed', 'sent', 'offering', 'Đang đề nghị (offer)', null],
            'Chấp nhận offer' => ['passed', 'accepted', 'awaiting_onboard', 'Chờ nhận việc', null],
            'Đã tuyển' => ['hired', 'accepted', 'hired', 'Đã tuyển', null],
            'Loại CV' => ['rejected', null, 'not_passed', 'Không đạt', 'Loại ở vòng CV'],
            'Trượt PV' => ['failed', null, 'not_passed', 'Không đạt', 'Trượt phỏng vấn'],
            'Không tham gia PV' => ['interview_declined', null, 'withdrawn', 'Đã rút lui', 'Không tham gia phỏng vấn'],
            'Từ chối offer' => ['offer_declined', 'declined', 'withdrawn', 'Đã rút lui', 'Từ chối offer'],
            'Không phản hồi offer' => ['offer_declined', 'expired', 'withdrawn', 'Đã rút lui', 'Không phản hồi offer'],
        ];
    }

    // Buổi PV đang hẹn: theo phản hồi của ứng viên + đã tới giờ hẹn chưa.
    public function test_scheduled_interview_stage_follows_confirmation_and_time(): void
    {
        $future = Carbon::now()->addHour();
        $past = Carbon::now()->subMinute();
        $stage = fn (?string $confirm, $at) => RecruitmentCandidate::stageFor('interview_scheduled', null, $confirm, $at);

        $this->assertSame(['key' => 'invited', 'label' => 'Đã hẹn – chờ xác nhận', 'reason' => null], $stage('pending', $future));
        $this->assertSame(['key' => 'invited', 'label' => 'Đã hẹn – chờ xác nhận', 'reason' => 'Ứng viên xin đổi lịch'], $stage('reschedule_requested', $future));
        $this->assertSame('awaiting_interview', $stage('confirmed', $future)['key']);
        $this->assertSame('Chờ phỏng vấn', $stage('confirmed', $future)['label']);
        // Tới giờ hẹn -> tự thành "Đang phỏng vấn"; chưa xác nhận thì dù quá giờ vẫn chờ xác nhận.
        $this->assertSame(['key' => 'interviewing', 'label' => 'Đang phỏng vấn', 'reason' => null], $stage('confirmed', $past));
        $this->assertSame('invited', $stage('pending', $past)['key']);
    }

    #[DataProvider('cases')]
    public function test_stage_mapping(string $status, ?string $offer, string $key, string $label, ?string $reason): void
    {
        $this->assertSame(['key' => $key, 'label' => $label, 'reason' => $reason], RecruitmentCandidate::stageFor($status, $offer));
    }
}
