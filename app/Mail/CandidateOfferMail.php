<?php

namespace App\Mail;

use App\Models\RecruitmentOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Thư mời nhận việc — có nút dẫn tới trang trả lời (link chứa mã bảo mật, xem
// RecruitmentOfferService). Mã chỉ có trong email, DB lưu bản băm.
class CandidateOfferMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly RecruitmentOffer $offer, public readonly string $responseUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Thư mời nhận việc - '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment.offer');
    }
}
