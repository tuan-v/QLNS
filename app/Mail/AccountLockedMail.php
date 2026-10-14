<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Báo chủ tài khoản: tài khoản vừa bị khóa tạm vì nhập sai mật khẩu nhiều lần
// (LoginLockoutService). Không phải họ thì nên đổi mật khẩu.
class AccountLockedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly int $minutes,
        public readonly ?string $ip,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Cảnh báo: tài khoản QLNS tạm khóa do đăng nhập sai nhiều lần');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-locked', with: [
            'forgotUrl' => rtrim((string) config('app.url'), '/').'/quen-mat-khau',
            'lockedAt' => now()->format('H:i d/m/Y'),
        ]);
    }
}
