<?php

namespace App\Modules\Platform\Billing\Console;

use App\Modules\Platform\Billing\BillingService;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rotina da cobrança (diária e a cada poucos minutos é seguro: tudo idempotente):
 * gera a próxima fatura, confere Pix pendentes e manda os avisos.
 */
class RunBillingCommand extends Command
{
    protected $signature = 'billing:run {--date= : Data de referência (AAAA-MM-DD), para testes}';

    protected $description = 'Gera faturas da mensalidade SerTec, confere Pix e envia avisos';

    public function handle(TenantContext $context, BillingService $billing): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay() : CarbonImmutable::today();
        $created = 0;

        foreach (Tenant::query()->orderBy('id')->cursor() as $tenant) {
            $created += $context->run($tenant, function () use ($billing, $today) {
                $new = 0;
                $sub = Subscription::query()->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_TRIALING])
                    ->with('plan')->latest('id')->first();
                if ($sub !== null && $billing->openInvoice($sub) === null && $billing->ensureUpcoming($sub, today: $today) !== null) {
                    $new++;
                }
                foreach (Invoice::query()->where('status', Invoice::OPEN)->get() as $invoice) {
                    if ($invoice->mp_payment_id !== null && ($invoice->synced_at === null || $invoice->synced_at->lt(now()->subMinutes(2)))) {
                        $invoice = $billing->syncPayment($invoice);
                    }
                    $billing->remind($invoice, $today);
                }

                return $new;
            });
        }

        $this->info("Faturas geradas: {$created}");

        return self::SUCCESS;
    }
}
