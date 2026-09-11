<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly LeaveRequest $leaveRequest,
        public readonly ?string $comment,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = $this->leaveRequest->status === 'approved'
            ? 'Đơn xin nghỉ phép của bạn đã được duyệt'
            : 'Đơn xin nghỉ phép của bạn đã bị từ chối';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.leave-decision');
    }
}
