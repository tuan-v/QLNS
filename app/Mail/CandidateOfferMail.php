<?php

namespace App\Mail;

use App\Models\RecruitmentOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Thư mời nhận việc — KHÔNG có link (ứng viên ngại bấm link vì sợ lừa đảo): ghi thông tin
// người liên hệ của công ty để ứng viên gọi / nhắn / trả lời email; Reply-To = email người liên
// hệ. HR ghi nhận phản hồi trên hệ thống (RecruitmentOfferService::recordResponse).
class CandidateOfferMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly RecruitmentOffer $offer)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Thư mời nhận việc - '.config('app.name'),
            replyTo: $this->offer->contact_email ? [new Address($this->offer->contact_email, $this->offer->contact_name)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment.offer');
    }
}
