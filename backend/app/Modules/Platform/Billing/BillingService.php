<?php

namespace App\Modules\Platform\Billing;

use App\Http\Errors\ApiException;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\RemotePayment;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Billing\Jobs\SendInvoiceNotice;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Platform\Models\Subscription;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mensalidade SerTec (D81–D84). Roda sempre dentro do contexto do tenant
 * dono da assinatura (o comando diário e o webhook entram nele).
 *
 * Datas: `paid_until` é o último dia já pago. O próximo período começa no dia
 * seguinte e vence nesse mesmo dia. A fatura nasce LEAD_DAYS antes.
 */
final class BillingService
{
    public const LEAD_DAYS = 7;

    /** Primeira cobrança (sem nada pago ainda): vence em alguns dias. */
    public const FIRST_DUE_DAYS = 5;

    public const REMIND_BEFORE_DAYS = 3;

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function openInvoice(Subscription $subscription): ?Invoice
    {
        return Invoice::query()->where('subscription_id', $subscription->id)->where('status', Invoice::OPEN)->orderBy('period_start')->first();
    }

    /**
     * Gera a próxima fatura se chegou a hora (ou se $force). Cortesia/sem preço: nada.
     */
    public function ensureUpcoming(Subscription $subscription, bool $force = false, ?CarbonImmutable $today = null): ?Invoice
    {
        $today ??= CarbonImmutable::today();
        $price = $subscription->effectivePrice();
        if (! in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_TRIALING], true)
            || $price === null || Money::toCents($price) <= 0) {
            return null;
        }
        if (($open = $this->openInvoice($subscription)) !== null) {
            return $open;
        }

        $paidUntil = $subscription->paid_until === null ? null : CarbonImmutable::instance($subscription->paid_until);
        $start = $paidUntil?->addDay() ?? $today;
        if (! $force && $paidUntil !== null && $today->diffInDays($start, false) > self::LEAD_DAYS) {
            return null;
        }
        $months = $subscription->billing_months;

        return Invoice::query()->firstOrCreate(
            ['subscription_id' => $subscription->id, 'period_start' => $start->toDateString()],
            [
                'plan_name' => $subscription->plan->name,
                'months' => $months,
                'amount' => $price,
                'period_end' => $start->addMonthsNoOverflow($months)->subDay()->toDateString(),
                'due_date' => ($paidUntil === null ? $today->addDays(self::FIRST_DUE_DAYS) : $start)->toDateString(),
                'status' => Invoice::OPEN,
            ],
        );
    }

    /**
     * Baixa da fatura (Pix confirmado ou pagamento manual). Idempotente.
     */
    public function markPaid(Invoice $invoice, string $via, ?string $note = null): Invoice
    {
        $paid = DB::transaction(function () use ($invoice, $via, $note) {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Invoice::OPEN) {
                return null;
            }
            $locked->forceFill(['status' => Invoice::PAID, 'paid_at' => Carbon::now(), 'paid_via' => $via, 'note' => $note ?? $locked->note])->save();

            /** @var Subscription $sub */
            $sub = Subscription::query()->whereKey($locked->subscription_id)->lockForUpdate()->firstOrFail();
            $until = $sub->paid_until === null || $sub->paid_until->lt($locked->period_end) ? $locked->period_end : $sub->paid_until;
            $sub->forceFill([
                'paid_until' => $until,
                'status' => $sub->status === Subscription::STATUS_PAST_DUE || $sub->status === Subscription::STATUS_TRIALING ? Subscription::STATUS_ACTIVE : $sub->status,
            ])->save();

            return $locked;
        });

        if ($paid !== null) {
            app(AuditLogger::class)->record('billing.invoice_paid', null, ['invoice' => $paid->public_id, 'via' => $via, 'amount' => $paid->amount]);
            SendInvoiceNotice::dispatch($paid->id, SendInvoiceNotice::PAID);
        }

        return $invoice->refresh();
    }

    public function cancel(Invoice $invoice, ?string $note): Invoice
    {
        if ($invoice->status !== Invoice::OPEN) {
            throw new ApiException(409, 'invoice_not_open', 'Só faturas em aberto podem ser canceladas.');
        }
        $invoice->forceFill(['status' => Invoice::CANCELLED, 'note' => $note, 'pix_code' => null, 'pix_qr_base64' => null, 'pix_expires_at' => null])->save();

        return $invoice;
    }

    /**
     * Pix na conta da SerTec. Reaproveita o código vigente; senão cria outro.
     */
    public function pix(Invoice $invoice, string $payerEmail, string $payerName, bool $secure): Invoice
    {
        if ($invoice->status !== Invoice::OPEN) {
            throw new ApiException(409, 'invoice_not_open', 'Esta fatura não está em aberto.');
        }
        if ($invoice->hasValidPix()) {
            return $invoice;
        }
        $connection = PlatformMercadoPago::connection()
            ?? throw new ApiException(503, 'billing_unavailable', 'O pagamento por Pix ainda não está disponível. Fale com a SerTec.');

        $attempt = $invoice->pix_attempts + 1;
        $expires = CarbonImmutable::now()->addDay();
        try {
            $remote = $this->gateway->createPix($connection, [
                'amount_cents' => Money::toCents($invoice->amount),
                'description' => "Mensalidade SerTec Food — {$invoice->plan_name}",
                'reference' => $invoice->reference(),
                'payer_email' => $payerEmail,
                'payer_name' => $payerName,
                'expires_at' => $expires,
                'notification_url' => $secure ? PlatformMercadoPago::notificationUrl() : null,
            ], "invoice-{$invoice->public_id}-{$attempt}");
        } catch (GatewayException $e) {
            Log::warning('billing.pix_failed', ['invoice' => $invoice->public_id, 'error' => $e->getMessage()]);
            throw new ApiException(502, 'billing_provider_error', 'Não foi possível gerar o Pix agora. Tente de novo em instantes.');
        }
        if ($remote->pixQrCode === null) {
            throw new ApiException(502, 'billing_provider_error', 'Não foi possível gerar o Pix agora. Tente de novo em instantes.');
        }

        $invoice->forceFill([
            'mp_payment_id' => $remote->id,
            'pix_code' => $remote->pixQrCode,
            'pix_qr_base64' => $remote->pixQrBase64,
            'pix_expires_at' => $remote->expiresAt ?? $expires,
            'pix_attempts' => $attempt,
            'synced_at' => Carbon::now(),
        ])->save();

        return $invoice;
    }

    /**
     * Consulta o pagamento no Mercado Pago da SerTec e dá baixa se aprovado.
     * O status SEMPRE vem da API, nunca do corpo do webhook.
     */
    public function syncPayment(Invoice $invoice, ?RemotePayment $remote = null): Invoice
    {
        if ($invoice->status !== Invoice::OPEN || ($remote === null && $invoice->mp_payment_id === null)) {
            return $invoice;
        }
        $connection = PlatformMercadoPago::connection();
        if ($connection === null) {
            return $invoice;
        }
        try {
            $remote ??= $this->gateway->getPayment($connection, (string) $invoice->mp_payment_id);
        } catch (GatewayException) {
            return $invoice;
        }
        $invoice->forceFill(['synced_at' => Carbon::now()])->save();

        if ($remote->externalReference === $invoice->reference()
            && $remote->status === 'approved'
            && $remote->amountCents >= Money::toCents($invoice->amount)
            && ($connection->account_id === null || $remote->collectorId === null || $remote->collectorId === $connection->account_id)) {
            if ($invoice->mp_payment_id !== $remote->id) {
                $invoice->forceFill(['mp_payment_id' => $remote->id])->save();
            }

            return $this->markPaid($invoice, 'pix', 'Pix confirmado pelo Mercado Pago');
        }

        return $invoice;
    }

    /**
     * Lembretes e situação: vence em breve (uma vez) e venceu (uma vez; vira past_due).
     */
    public function remind(Invoice $invoice, ?CarbonImmutable $today = null): void
    {
        $today ??= CarbonImmutable::today();
        if ($invoice->status !== Invoice::OPEN) {
            return;
        }
        $daysToDue = $today->diffInDays(CarbonImmutable::instance($invoice->due_date), false);

        if ($daysToDue >= 0 && $daysToDue <= self::REMIND_BEFORE_DAYS && $invoice->reminded_due_at === null) {
            $invoice->forceFill(['reminded_due_at' => Carbon::now()])->save();
            SendInvoiceNotice::dispatch($invoice->id, SendInvoiceNotice::DUE_SOON);
        }

        if ($daysToDue < 0) {
            Subscription::query()->whereKey($invoice->subscription_id)->where('status', Subscription::STATUS_ACTIVE)
                ->update(['status' => Subscription::STATUS_PAST_DUE]);
            if ($invoice->reminded_overdue_at === null) {
                $invoice->forceFill(['reminded_overdue_at' => Carbon::now()])->save();
                SendInvoiceNotice::dispatch($invoice->id, SendInvoiceNotice::OVERDUE);
            }
        }
    }
}
