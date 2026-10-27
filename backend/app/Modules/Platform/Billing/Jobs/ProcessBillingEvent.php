<?php

namespace App\Modules\Platform\Billing\Jobs;

use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Platform\Billing\BillingService;
use App\Modules\Platform\Billing\PlatformMercadoPago;
use App\Modules\Platform\Models\BillingEvent;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Webhook da conta SerTec: consulta o pagamento na API (fonte da verdade),
 * acha o restaurante e a fatura pela external_reference e dá baixa.
 */
class ProcessBillingEvent implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $eventId) {}

    public function handle(PaymentGateway $gateway, BillingService $billing, TenantContext $context): void
    {
        $event = BillingEvent::query()->find($this->eventId);
        $connection = PlatformMercadoPago::connection();
        if ($event === null || $event->status === 'processed' || $connection === null) {
            return;
        }

        try {
            $remote = $gateway->getPayment($connection, $event->resource_id);
        } catch (GatewayException $e) {
            if ($e->httpStatus === 404 || $e->reason === 'invalid_payment_id') {
                $event->forceFill(['status' => 'ignored'])->save();

                return;
            }
            throw $e;
        }

        $ref = Invoice::parseReference($remote->externalReference);
        $tenant = $ref === null ? null : Tenant::query()->where('public_id', $ref['tenant'])->first();
        if ($ref === null || $tenant === null) {
            $event->forceFill(['status' => 'ignored'])->save();

            return;
        }

        $context->run($tenant, function () use ($ref, $remote, $billing) {
            $invoice = Invoice::query()->where('public_id', $ref['invoice'])->first();
            if ($invoice !== null) {
                $billing->syncPayment($invoice, $remote);
            }
        });
        $event->forceFill(['status' => 'processed'])->save();
    }
}
