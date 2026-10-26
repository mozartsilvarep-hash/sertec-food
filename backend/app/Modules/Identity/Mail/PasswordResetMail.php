<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Link para criar uma senha nova. Vale por config('auth.passwords.users.expire')
 * minutos e uma única vez.
 */
class PasswordResetMail extends Mailable
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly string $panel,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Redefinir sua senha · '.$this->panel);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset', text: 'mail.password-reset-text');
    }
}
