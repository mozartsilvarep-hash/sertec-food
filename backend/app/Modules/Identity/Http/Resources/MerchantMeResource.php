<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions;
use App\Modules\Platform\Billing\BillingService;
use App\Modules\Platform\Features\FeatureGate;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MerchantMeResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = app(TenantContext::class)->get();
        $access = app(TenantAccess::class)->for($this->resource);
        $gate = app(FeatureGate::class);

        return [
            'user' => [
                'id' => $this->public_id,
                'name' => $this->name,
                'email' => $this->email,
            ],
            'tenant' => [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
            ],
            'roles' => $access->roles,
            'permissions' => $access->permissions,
            'all_branches' => $access->hasAllBranches(),
            // Módulos contratados e funcionalidades liberadas (o painel esconde o resto).
            'modules' => $gate->modules(),
            // Bloqueado por mensalidade atrasada: o painel leva para Assinatura.
            'blocked' => $gate->blocked(),
            'features' => array_keys(array_filter($gate->features())),
            // Aviso da mensalidade SerTec (só para quem pode pagar).
            'billing' => in_array(Permissions::BILLING_MANAGE, $access->permissions, true) ? self::billingNotice() : null,
        ];
    }

    /**
     * @return array{invoice_id: string, due_date: string, amount: string, overdue: bool, block_date: string|null}|null
     */
    private static function billingNotice(): ?array
    {
        $invoice = Invoice::query()->where('status', Invoice::OPEN)
            ->where('due_date', '<=', now()->addDays(BillingService::REMIND_BEFORE_DAYS)->toDateString())
            ->orderBy('due_date')->first();

        return $invoice === null ? null : [
            'invoice_id' => $invoice->public_id,
            'due_date' => $invoice->due_date->toDateString(),
            'amount' => $invoice->amount,
            'overdue' => $invoice->isOverdue(),
            // Atrasada: dia em que o sistema bloqueia (se o bloqueio automático estiver ligado).
            'block_date' => $invoice->isOverdue() && Subscription::query()->whereKey($invoice->subscription_id)->value('auto_block')
                ? BillingService::blockDate($invoice)->toDateString() : null,
        ];
    }
}
