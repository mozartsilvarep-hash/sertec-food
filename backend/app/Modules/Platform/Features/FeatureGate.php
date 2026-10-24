<?php

namespace App\Modules\Platform\Features;

use App\Modules\Platform\Models\Subscription;
use App\Modules\Tenancy\TenantContext;

/**
 * Responde se o tenant do contexto tem uma funcionalidade liberada pela
 * assinatura vigente (trialing/active e não expirada).
 */
final class FeatureGate
{
    /** @var array<int, array<string, bool>> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $context) {}

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

        if ($subscription === null || ! $subscription->plan->active) {
            return [];
        }

        return array_map(fn ($v) => $v === true, $subscription->plan->features_json);
    }
}
