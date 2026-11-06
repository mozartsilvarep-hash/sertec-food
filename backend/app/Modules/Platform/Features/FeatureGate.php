<?php

namespace App\Modules\Platform\Features;

use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Modules\ModuleKey;
use App\Modules\Tenancy\TenantContext;

/**
 * Responde se o tenant do contexto tem uma funcionalidade liberada pela
 * assinatura vigente (trialing/active e não expirada): recursos do plano +
 * módulos contratados (D100).
 */
final class FeatureGate
{
    /** @var array<int, array<string, bool>> */
    private array $cache = [];

    /** @var array<int, bool> */
    private array $blocked = [];

    public function __construct(private readonly TenantContext $context) {}

    /** Esquece o que foi lido (após mudar a assinatura na mesma requisição). */
    public function forget(): void
    {
        $this->cache = [];
        $this->blocked = [];
    }

    public function enabled(string $feature): bool
    {
        return ($this->features()[$feature] ?? false) === true;
    }

    /**
     * @return array<string, bool>
     */
    public function features(): array
    {
        $tenantId = $this->context->id();

        return $this->cache[$tenantId] ??= $this->load();
    }

    /**
     * @return array<string, bool>
     */
    private function load(): array
    {
        $subscription = Subscription::query()->current()->with('plan')->first();
        // Bloqueado por atraso: nada funciona até pagar (D101).
        if ($subscription?->blocked_at !== null) {
            return array_fill_keys([...Feature::all(), ...ModuleKey::features()], false);
        }

        // Plano: recursos extras. As funcionalidades dos módulos (cardápio
        // online, caixa, mesas, estoque) vêm só dos módulos contratados.
        $features = $subscription !== null && $subscription->plan->active
            ? array_map(fn ($v) => $v === true, $subscription->plan->features_json)
            : [];
        foreach (ModuleKey::features() as $f) {
            $features[$f] = false;
        }
        // Sem assinatura: só o Cardápio digital (como sempre foi).
        $modules = $subscription?->modules() ?? [ModuleKey::Menu];
        foreach ($modules as $m) {
            $features[$m->feature()] = true;
        }

        return $features;
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        $subscription = Subscription::query()->current()->first();

        return array_map(fn (ModuleKey $m) => $m->value, $subscription?->modules() ?? [ModuleKey::Menu]);
    }

    /** Bloqueado por mensalidade atrasada (o painel mostra só a tela de pagar). */
    public function blocked(): bool
    {
        $tenantId = $this->context->id();

        return $this->blocked[$tenantId] ??= Subscription::query()->current()->whereNotNull('blocked_at')->exists();
    }
}
