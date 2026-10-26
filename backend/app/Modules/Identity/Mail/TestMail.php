<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Enviar e-mail de teste" do painel SerTec. */
class TestMail extends Mailable
{
    public function __construct(public readonly string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Teste de envio · SerTec Food');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.test', text: 'mail.test-text');
    }
}
