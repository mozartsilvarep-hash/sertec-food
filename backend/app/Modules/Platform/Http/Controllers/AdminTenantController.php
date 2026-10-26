<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Auth\SessionTerminator;
use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Tenants\TenantProvisioner;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Painel SerTec: estabelecimentos. Roda em modo plataforma (leitura entre
 * tenants). Escritas exigem superadmin; suporte só consulta.
 */
class AdminTenantController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
        ]);

        $tenants = Tenant::query()
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('slug', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['domains', 'subscriptions.plan'])
            ->orderByDesc('id')
            ->cursorPaginate(50);

        $ids = collect($tenants->items())->pluck('id');
        $orders30 = Order::query()->whereIn('tenant_id', $ids)->where('placed_at', '>=', now()->subDays(30))
            ->selectRaw('tenant_id, COUNT(*) AS c')->groupBy('tenant_id')->toBase()->pluck('c', 'tenant_id');
        $mp = IntegrationConnection::query()->whereIn('tenant_id', $ids)->where('provider', Provider::MercadoPago)
            ->where('status', IntegrationConnection::ACTIVE)->pluck('mode', 'tenant_id');

        return new JsonResponse([
            'data' => collect($tenants->items())->map(fn (Tenant $t) => [
                ...self::summary($t),
                'orders_30d' => (int) ($orders30[$t->id] ?? 0),
                'payments' => $mp[$t->id] ?? null,
            ])->values(),
            'meta' => ['next_cursor' => $tenants->nextCursor()?->encode()],
        ]);
    }

    public function store(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $this->requireSuperadmin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'max:63'],
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_email' => ['required', 'email', 'max:191'],
            'plan' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'timezone:all'],
            'activate' => ['sometimes', 'boolean'],
        ]);

        if (Tenant::query()->where('slug', strtolower($data['slug']))->exists()) {
            throw new ApiException(409, 'slug_taken', 'Este endereço já está em uso.');
        }
        if (isset($data['plan']) && ! Plan::query()->where('slug', $data['plan'])->exists()) {
            throw new ApiException(422, 'plan_not_found', 'Plano não encontrado.');
        }

        try {
            $result = $provisioner->provision(
                $data['name'], $data['slug'], $data['owner_email'], $data['owner_name'],
                $data['plan'] ?? null, (bool) ($data['activate'] ?? false), $data['timezone'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, 'slug_invalid', $e->getMessage());
        }

        return new JsonResponse(['data' => [
            ...$this->detail($result['tenant']->fresh() ?? $result['tenant']),
            // Mostrada UMA vez; null quando o e-mail já tinha conta.
            'owner_password' => $result['owner_password'],
        ]], 201);
    }

    public function plans(): JsonResponse
    {
        return new JsonResponse(['data' => Plan::query()->orderBy('id')->get()->map(fn (Plan $p) => [
            'slug' => $p->slug, 'name' => $p->name, 'monthly_price' => $p->monthly_price, 'active' => $p->active,
        ])]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return new JsonResponse(['data' => $this->detail($tenant)]);
    }

    public function update(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'status' => ['sometimes', Rule::enum(TenantStatus::class)],
            'plan' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $changes = [];
        if (isset($data['name']) && $data['name'] !== $tenant->name) {
            $changes['name'] = [$tenant->name, $data['name']];
            $tenant->name = $data['name'];
        }
        if (isset($data['status'])) {
            $next = TenantStatus::from($data['status']);
            if ($tenant->status === TenantStatus::Cancelled && $next !== TenantStatus::Cancelled) {
                throw new ApiException(409, 'tenant_cancelled', 'Estabelecimento cancelado não pode ser reativado; crie outro.');
            }
            if ($next !== $tenant->status) {
                $changes['status'] = [$tenant->status->value, $next->value];
                $tenant->status = $next;
            }
        }
        $tenant->save();

        if (array_key_exists('plan', $data)) {
            $current = Subscription::query()->where('tenant_id', $tenant->id)->where('status', Subscription::STATUS_ACTIVE)->first();
            $plan = $data['plan'] === null ? null : (Plan::query()->where('slug', $data['plan'])->first()
                ?? throw new ApiException(422, 'plan_not_found', 'Plano não encontrado.'));
            if ($current?->plan_id !== $plan?->id) {
                $current?->forceFill(['status' => Subscription::STATUS_CANCELLED])->save();
                if ($plan !== null) {
                    Subscription::query()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => Subscription::STATUS_ACTIVE]);
                }
                $changes['plan'] = [$current?->plan->slug, $plan?->slug];
            }
        }

        if ($changes !== []) {
            $audit->record('tenant.updated', $tenant, $changes, tenant: $tenant);
        }

        return new JsonResponse(['data' => $this->detail($tenant->refresh())]);
    }

    /**
     * Nova senha temporária para o proprietário (mostrada uma vez) e fim das
     * sessões dele (a conta é global: vale para todos os vínculos do usuário).
     */
    public function resetOwnerPassword(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $owner = $this->owner($tenant) ?? throw new ApiException(404, 'owner_not_found', 'Proprietário não encontrado.');

        if ($owner->isPlatformStaff()) {
            throw new ApiException(409, 'owner_is_staff', 'Esta conta é da equipe SerTec; redefina pelo terminal.');
        }

        $password = Str::password(16, symbols: false);
        $owner->forceFill(['password' => $password, 'remember_token' => null])->save();
        $ended = SessionTerminator::forUser($owner->id);

        $audit->record('tenant.owner_password_reset', $tenant, ['owner' => $owner->public_id, 'sessions_ended' => $ended], tenant: $tenant);

        return new JsonResponse(['data' => ['email' => $owner->email, 'password' => $password]]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Tenant $t): array
    {
        $primary = $t->domains->firstWhere('is_primary', true) ?? $t->domains->first();
        $subscription = $t->subscriptions->firstWhere('status', Subscription::STATUS_ACTIVE);

        return [
            'id' => $t->public_id,
            'name' => $t->name,
            'slug' => $t->slug,
            'status' => $t->status->value,
            'host' => $primary?->hostname,
            'plan' => $subscription?->plan->slug,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Tenant $tenant): array
    {
        $tenant->load(['domains', 'subscriptions.plan']);
        $owner = $this->owner($tenant);
        $connection = IntegrationConnection::query()->where('tenant_id', $tenant->id)->where('provider', Provider::MercadoPago)->first();

        return [
            ...self::summary($tenant),
            'timezone' => $tenant->timezone,
            'owner' => $owner === null ? null : ['name' => $owner->name, 'email' => $owner->email, 'last_login_at' => $owner->last_login_at?->toIso8601String()],
            'domains' => $tenant->domains->sortByDesc('is_primary')->values()->map(fn (TenantDomain $d) => [
                'id' => (string) $d->id,
                'hostname' => $d->hostname,
                'type' => $d->type->value,
                'verified' => $d->verified_at !== null,
                'is_primary' => $d->is_primary,
            ]),
            'branches' => Branch::query()->where('tenant_id', $tenant->id)->orderBy('id')->get()->map(fn (Branch $b) => [
                'id' => $b->public_id, 'name' => $b->name, 'active' => $b->active, 'phone' => $b->phone,
            ]),
            'staff_count' => TenantUser::query()->where('tenant_id', $tenant->id)->where('active', true)->count(),
            'orders_total' => Order::query()->where('tenant_id', $tenant->id)->count(),
            'last_order_at' => Order::query()->where('tenant_id', $tenant->id)->max('placed_at'),
            'payments' => $connection === null || $connection->status !== IntegrationConnection::ACTIVE ? null : [
                'mode' => $connection->mode, 'account_label' => $connection->account_label, 'verified_at' => $connection->verified_at?->toIso8601String(),
            ],
        ];
    }

    private function owner(Tenant $tenant): ?User
    {
        $roleId = Role::query()->whereNull('tenant_id')->where('key', Role::OWNER)->value('id');
        $membership = TenantUser::query()->where('tenant_id', $tenant->id)->where('role_id', $roleId)->orderBy('id')->first();

        return $membership?->user;
    }

    private function requireSuperadmin(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User || $user->platform_role !== PlatformRole::Superadmin) {
            throw ApiException::forbidden();
        }
    }
}
