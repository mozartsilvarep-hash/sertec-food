<?php

namespace App\Modules\Platform\Billing\Mail;

use App\Modules\Platform\Billing\Jobs\SendInvoiceNotice;
use App\Modules\Platform\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Vence em breve / venceu / pagamento confirmado (mensalidade SerTec Food). */
class InvoiceNoticeMail extends Mailable
{
    public string $amount;

    public string $due;

    public string $period;

    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly string $store,
        Invoice $invoice,
        public readonly ?string $url,
    ) {
        $this->amount = 'R$ '.number_format((float) $invoice->amount, 2, ',', '.');
        $this->due = $invoice->due_date->format('d/m/Y');
        $this->period = $invoice->period_start->format('d/m/Y').' a '.$invoice->period_end->format('d/m/Y');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            SendInvoiceNotice::DUE_SOON => "Sua mensalidade vence em {$this->due} · SerTec Food",
            SendInvoiceNotice::OVERDUE => 'Mensalidade em atraso · SerTec Food',
            default => 'Pagamento confirmado · SerTec Food',
        });
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invoice-notice', text: 'mail.invoice-notice-text');
    }
}
