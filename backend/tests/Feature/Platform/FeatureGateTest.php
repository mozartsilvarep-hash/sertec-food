<?php

use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Features\FeatureGate;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;

function subscribe($tenant, Plan $plan, array $attributes = []): Subscription
{
    return asPlatform(fn () => Subscription::factory()->create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id, ...$attributes,
    ]));
}

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->site = Plan::factory()->withFeatures([Feature::SITE_MODE, Feature::COUPONS])->create();
    $this->basic = Plan::factory()->withFeatures([Feature::THERMAL_PRINT])->create();
});

it('libera apenas as funcionalidades do plano do tenant do contexto', function () {
    subscribe($this->a, $this->site);
    subscribe($this->b, $this->basic);

    inTenant($this->a, function () {
        $gate = new FeatureGate(ctx());
        expect($gate->enabled(Feature::SITE_MODE))->toBeTrue()
            ->and($gate->enabled(Feature::THERMAL_PRINT))->toBeFalse();
    });

    inTenant($this->b, function () {
        $gate = new FeatureGate(ctx());
        expect($gate->enabled(Feature::SITE_MODE))->toBeFalse()
            ->and($gate->enabled(Feature::THERMAL_PRINT))->toBeTrue();
    });
});

it('assinatura vencida, cancelada ou plano inativo não libera nada', function () {
    subscribe($this->a, $this->site, ['period_end' => now()->subDay()]);
    subscribe($this->b, $this->site, ['status' => Subscription::STATUS_CANCELLED]);

    expect(inTenant($this->a, fn () => (new FeatureGate(ctx()))->enabled(Feature::SITE_MODE)))->toBeFalse();
    expect(inTenant($this->b, fn () => (new FeatureGate(ctx()))->enabled(Feature::SITE_MODE)))->toBeFalse();

    $c = makeTenant('tenant-c');
    $this->site->update(['active' => false]);
    subscribe($c, $this->site);
    expect(inTenant($c, fn () => (new FeatureGate(ctx()))->features()))->toBe([]);
});

it('sem contexto de tenant, a checagem falha fechada', function () {
    expect(fn () => (new FeatureGate(ctx()))->enabled(Feature::SITE_MODE))
        ->toThrow(MissingTenantContext::class);
});
