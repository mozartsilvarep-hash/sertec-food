<?php

namespace Tests\Support;

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\PlanSeeder;

final class Marketing
{
    /** Assina o plano que inclui cupons. */
    public static function withCoupons(Tenant $tenant): void
    {
        if (! Plan::query()->where('slug', 'site-cardapio')->exists()) {
            (new PlanSeeder)->run();
        }
        inTenant($tenant, fn () => Subscription::query()->create([
            'plan_id' => Plan::query()->where('slug', 'site-cardapio')->value('id'),
            'status' => Subscription::STATUS_ACTIVE,
        ]));
    }
}
