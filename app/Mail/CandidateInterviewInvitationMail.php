<?php

namespace App\Mail;

use App\Models\RecruitmentInterview;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Mời phỏng vấn (hoặc báo dời lịch khi $rescheduled = true).
class CandidateInterviewInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly RecruitmentInterview $interview,
        public readonly bool $rescheduled = false,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->rescheduled ? 'Thay đổi lịch phỏng vấn' : 'Thư mời phỏng vấn';

        return new Envelope(subject: $subject.' - '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment.interview-invitation');
    }
}
