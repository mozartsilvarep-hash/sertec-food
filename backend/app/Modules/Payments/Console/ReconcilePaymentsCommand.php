<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Jobs\ProcessPaymentEvent;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Services\PaymentSync;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Rede de segurança dos webhooks (agendado a cada minuto):
 *  - consulta no provedor os pagamentos ainda abertos (webhook perdido,
 *    ambiente sem HTTPS público);
 *  - vence os que passaram do prazo e cancela o pedido que esperava;
 *  - reenfileira notificações que falharam.
 * Cada tenant roda no próprio contexto (TenantScope ativo).
 */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile {--tenant= : slug de um tenant}';

    protected $description = 'Confere pagamentos online pendentes no Mercado Pago e vence os expirados';

    public function handle(TenantContext $context, PaymentSync $sync): int
    {
        $tenants = Tenant::query()->where('status', TenantStatus::Active)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')->get();

        $totals = ['checked' => 0, 'expired' => 0, 'errors' => 0];

        foreach ($tenants as $tenant) {
            $context->run($tenant, function () use ($sync, &$totals) {
                if (IntegrationConnection::activeFor(Provider::MercadoPago) === null) {
                    return;
                }

                $open = Payment::query()
                    ->whereIn('status', [PaymentAttemptStatus::Pending, PaymentAttemptStatus::InReview, PaymentAttemptStatus::Rejected, PaymentAttemptStatus::Cancelled])
                    ->where('created_at', '>', Carbon::now()->subDays(2))
                    ->orderBy('id')->limit(200)->get();

                foreach ($open as $payment) {
                    try {
                        if ($payment->isStarted() && ($payment->last_synced_at === null || $payment->last_synced_at->lt(Carbon::now()->subSeconds(50)))) {
                            $payment = $sync->refresh($payment);
                            $totals['checked']++;
                        }
                        if ($sync->expire($payment)->status === PaymentAttemptStatus::Expired) {
                            $totals['expired']++;
                        }
                    } catch (GatewayException $e) {
                        $totals['errors']++;
                    }
                }

                PaymentEvent::query()->where('status', PaymentEvent::FAILED)->where('attempts', '<', 10)
                    ->where('updated_at', '<', Carbon::now()->subMinutes(5))->limit(50)->get()
                    ->each(function (PaymentEvent $event) {
                        try {
                            ProcessPaymentEvent::dispatch($event->id);
                        } catch (Throwable) {
                            // Fila síncrona em dev: a falha já foi registrada no evento.
                        }
                    });
            });
        }

        $this->info("Conferidos: {$totals['checked']} · vencidos: {$totals['expired']} · erros: {$totals['errors']}");

        return self::SUCCESS;
    }
}
