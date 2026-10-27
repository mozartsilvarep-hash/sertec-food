<?php

namespace App\Modules\Platform\Billing\Jobs;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Billing\Mail\InvoiceNoticeMail;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa o(s) proprietário(s) do restaurante: fatura vence em breve, venceu
 * ou foi paga. Na fila vão só os ids (e-mails são lidos no envio).
 */
class SendInvoiceNotice implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DUE_SOON = 'due_soon';

    public const OVERDUE = 'overdue';

    public const PAID = 'paid';

    public int $tries = 3;

    public int $tenantId;

    public function __construct(public readonly int $invoiceId, public readonly string $kind)
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    public function handle(TenantContext $context): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        $context->run($tenant, function () use ($tenant) {
            $invoice = Invoice::query()->find($this->invoiceId);
            if ($invoice === null || ($this->kind !== self::PAID && $invoice->status !== Invoice::OPEN)) {
                return;
            }
            $ownerRole = Role::query()->whereNull('tenant_id')->where('key', Role::OWNER)->value('id');
            $owners = TenantUser::query()->where('role_id', $ownerRole)->with('user')->get()
                ->map(fn (TenantUser $m) => $m->getRelation('user'))
                ->filter(fn (mixed $u) => $u instanceof User && $u->active && ! $u->isPlatformStaff());
            $host = TenantDomain::query()->where('is_primary', true)->value('hostname') ?? TenantDomain::query()->value('hostname');
            $url = $host ? 'https://'.$host.'/painel/assinatura' : null;

            foreach ($owners as $owner) {
                try {
                    Mail::to($owner->email)->send(new InvoiceNoticeMail($this->kind, strtok($owner->name, ' ') ?: $owner->name, $tenant->name, $invoice, $url));
                } catch (Throwable $e) {
                    Log::error('mail.invoice_notice_failed', ['invoice' => $invoice->public_id, 'kind' => $this->kind, 'error' => $e::class]);
                }
            }
        });
    }
}
