<?php

namespace App\Mail;

use App\Models\RecruitmentCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// CV không được chọn vào vòng phỏng vấn — không nêu lý do nội bộ của Admin.
class CandidateReviewRejectedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly RecruitmentCandidate $candidate)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kết quả xét duyệt hồ sơ ứng tuyển - '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment.review-rejected');
    }
}
