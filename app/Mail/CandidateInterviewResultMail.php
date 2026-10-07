<?php

namespace App\Mail;

use App\Models\RecruitmentCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Kết quả phỏng vấn: đạt (HR sẽ liên hệ nhận việc) / chưa phù hợp.
class CandidateInterviewResultMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly RecruitmentCandidate $candidate)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kết quả phỏng vấn - '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment.interview-result');
    }
}
