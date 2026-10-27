<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Billing\BillingService;
use App\Modules\Platform\Billing\InvoicePresenter;
use App\Modules\Platform\Billing\PlatformMercadoPago;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\PlatformSetting;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Painel SerTec → cobrança: conta Mercado Pago da SerTec, mensalidade de cada
 * restaurante e faturas. Leitura para a equipe; escrita só superadmin.
 * Escritas entram no contexto do restaurante (TenantContext::run).
 */
class AdminBillingController
{
    private const TOKEN = '/^(APP_USR|TEST)-[A-Za-z0-9_-]{20,250}$/';

    /* ---------- Conta Mercado Pago da SerTec ---------- */

    public function settings(): JsonResponse
    {
        return new JsonResponse(['data' => self::presentSettings()]);
    }

    public function saveSettings(Request $request, PaymentGateway $gateway, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $current = PlatformMercadoPago::settings();
        $data = $request->validate([
            'access_token' => [$current ? 'nullable' : 'required', 'string', 'regex:'.self::TOKEN],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'remove_webhook_secret' => ['sometimes', 'boolean'],
        ], [
            'access_token.regex' => 'Cole o Access Token completo (começa com APP_USR- ou TEST-).',
            'webhook_secret.regex' => 'A assinatura secreta tem só letras e números.',
        ], ['access_token' => 'Access Token', 'webhook_secret' => 'assinatura secreta']);

        $token = $data['access_token'] ?? ($current['access_token'] ?? null);
        if (! is_string($token) || $token === '') {
            throw new ApiException(422, 'validation_failed', 'Informe o Access Token.');
        }
        $account = $gateway->account($token);
        if ($account->siteId !== null && $account->siteId !== 'MLB') {
            throw new ApiException(422, 'provider_account_not_brazil', 'Esta conta do Mercado Pago não é do Brasil.');
        }
        $secret = $request->boolean('remove_webhook_secret') ? null : ($data['webhook_secret'] ?? ($current['webhook_secret'] ?? null));

        PlatformSetting::write(PlatformMercadoPago::KEY, [
            'access_token' => $token,
            'webhook_secret' => $secret,
            'mode' => str_starts_with($token, 'TEST-') ? 'test' : 'live',
            'account_id' => $account->id !== '' ? $account->id : null,
            'account_label' => mb_substr($account->label, 0, 120),
            'token_hint' => substr($token, -4),
            'verified_at' => Carbon::now()->toIso8601String(),
        ]);
        $audit->record('billing.provider_connected', null, ['account' => $account->id, 'webhook_secret' => $secret !== null]);

        return new JsonResponse(['data' => self::presentSettings()]);
    }

    public function deleteSettings(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        PlatformSetting::write(PlatformMercadoPago::KEY, null);
        $audit->record('billing.provider_disconnected', null, []);

        return new JsonResponse(['data' => self::presentSettings()]);
    }

    /* ---------- Faturas de todos os restaurantes ---------- */

    public function invoices(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['open', 'overdue', 'paid', 'cancelled'])]]);
        $status = $data['status'] ?? null;
        $invoices = Invoice::query()->with('tenant')
            ->when($status === 'overdue', fn ($q) => $q->where('status', Invoice::OPEN)->where('due_date', '<', Carbon::today()->toDateString()))
            ->when($status !== null && $status !== 'overdue', fn ($q) => $q->where('status', $status))
            ->orderBy($status === 'paid' ? 'paid_at' : 'due_date', $status === 'paid' ? 'desc' : 'asc')
            ->limit(200)->get();

        $open = Invoice::query()->where('status', Invoice::OPEN);

        return new JsonResponse([
            'data' => $invoices->map(fn (Invoice $i) => [
                ...InvoicePresenter::invoice($i),
                'tenant' => ['id' => $i->tenant->public_id, 'name' => $i->tenant->name, 'status' => $i->tenant->status->value],
            ])->values(),
            'meta' => [
                'open_total' => number_format((float) (clone $open)->sum('amount'), 2, '.', ''),
                'overdue_total' => number_format((float) (clone $open)->where('due_date', '<', Carbon::today()->toDateString())->sum('amount'), 2, '.', ''),
                'paid_month' => number_format((float) Invoice::query()->where('status', Invoice::PAID)->where('paid_at', '>=', Carbon::now()->startOfMonth())->sum('amount'), 2, '.', ''),
            ],
        ]);
    }

    /* ---------- Mensalidade de um restaurante ---------- */

    public function tenant(Tenant $tenant, TenantContext $context): JsonResponse
    {
        return new JsonResponse(['data' => $context->run($tenant, fn () => $this->presentTenant())]);
    }

    public function updateTenant(Request $request, Tenant $tenant, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::exists('plans', 'slug')],
            'billing_months' => ['required', 'integer', Rule::in(array_keys(Plan::PERIODS))],
            'price' => ['nullable', 'regex:/^\d{1,7}([.,]\d{1,2})?$/'],
            'paid_until' => ['nullable', 'date_format:Y-m-d'],
        ], ['price.regex' => 'Valor inválido. Use o formato 99,90.'], ['billing_months' => 'período', 'price' => 'valor', 'paid_until' => 'pago até']);

        $plan = Plan::query()->where('slug', $data['plan'])->firstOrFail();
        $price = isset($data['price']) && $data['price'] !== '' ? number_format((float) str_replace(',', '.', (string) $data['price']), 2, '.', '') : null;
        if ($price === null && $plan->priceFor((int) $data['billing_months']) === null) {
            throw new ApiException(422, 'plan_period_unavailable', 'Este plano não tem preço para o período escolhido. Informe um valor próprio ou ajuste o plano.');
        }

        return new JsonResponse(['data' => $context->run($tenant, function () use ($data, $plan, $price, $tenant, $audit) {
            $sub = MerchantBillingController::subscription();
            $attrs = ['billing_months' => (int) $data['billing_months'], 'price' => $price];
            if (array_key_exists('paid_until', $data)) {
                $attrs['paid_until'] = $data['paid_until'];
            }
            if ($sub === null || $sub->plan_id !== $plan->id) {
                $sub?->forceFill(['status' => Subscription::STATUS_CANCELLED])->save();
                $sub = Subscription::query()->create([
                    'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => Subscription::STATUS_ACTIVE,
                    'paid_until' => $sub?->paid_until, ...$attrs,
                ]);
                // Faturas em aberto do plano antigo deixam de valer.
                Invoice::query()->where('status', Invoice::OPEN)->where('subscription_id', '!=', $sub->id)
                    ->update(['status' => Invoice::CANCELLED, 'note' => 'Plano alterado']);
            } else {
                $sub->forceFill($attrs)->save();
            }
            $audit->record('billing.subscription_updated', $tenant, ['plan' => $plan->slug, ...$attrs], tenant: $tenant);

            return $this->presentTenant();
        })]);
    }

    public function createInvoice(Request $request, Tenant $tenant, TenantContext $context, BillingService $billing): JsonResponse
    {
        $this->requireSuperadmin($request);

        return new JsonResponse(['data' => $context->run($tenant, function () use ($billing) {
            $sub = MerchantBillingController::subscription()
                ?? throw new ApiException(409, 'no_subscription', 'Este estabelecimento não tem plano ativo.');
            if ($billing->ensureUpcoming($sub->load('plan'), force: true) === null) {
                throw new ApiException(409, 'nothing_to_bill', 'Sem valor a cobrar (plano sem preço ou cortesia).');
            }

            return $this->presentTenant();
        })], 201);
    }

    public function payInvoice(Request $request, Invoice $invoice, TenantContext $context, BillingService $billing): JsonResponse
    {
        $this->requireSuperadmin($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        return new JsonResponse(['data' => $context->run($invoice->tenant, function () use ($invoice, $billing, $data) {
            $fresh = Invoice::query()->whereKey($invoice->id)->firstOrFail();
            if ($fresh->status !== Invoice::OPEN) {
                throw new ApiException(409, 'invoice_not_open', 'Esta fatura não está em aberto.');
            }
            $billing->markPaid($fresh, 'manual', $data['note'] ?? 'Baixa manual no painel SerTec');

            return $this->presentTenant();
        })]);
    }

    public function cancelInvoice(Request $request, Invoice $invoice, TenantContext $context, BillingService $billing, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        return new JsonResponse(['data' => $context->run($invoice->tenant, function () use ($invoice, $billing, $data, $audit) {
            $fresh = Invoice::query()->whereKey($invoice->id)->firstOrFail();
            $billing->cancel($fresh, $data['note'] ?? 'Cancelada no painel SerTec');
            $audit->record('billing.invoice_cancelled', null, ['invoice' => $fresh->public_id]);

            return $this->presentTenant();
        })]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTenant(): array
    {
        $sub = MerchantBillingController::subscription();

        return [
            'subscription' => InvoicePresenter::subscription($sub),
            'invoices' => Invoice::query()->orderByDesc('period_start')->limit(36)->get()->map(fn (Invoice $i) => InvoicePresenter::invoice($i))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentSettings(): array
    {
        $s = PlatformMercadoPago::settings();

        return [
            'connected' => $s !== null,
            'mode' => $s['mode'] ?? null,
            'account_label' => $s['account_label'] ?? null,
            'account_id' => $s['account_id'] ?? null,
            'token_hint' => $s['token_hint'] ?? null,
            'has_webhook_secret' => is_string($s['webhook_secret'] ?? null) && $s['webhook_secret'] !== '',
            'verified_at' => $s['verified_at'] ?? null,
            'webhook_url' => PlatformMercadoPago::notificationUrl(),
        ];
    }

    private function requireSuperadmin(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User || $user->platform_role !== PlatformRole::Superadmin) {
            throw ApiException::forbidden();
        }
    }
}
