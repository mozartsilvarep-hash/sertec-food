<?php

namespace App\Modules\Ordering\Console;

use App\Modules\Ordering\Privacy\CustomerPrivacy;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * LGPD — retenção: remove dados pessoais de pedidos finalizados há mais de
 * PRIVACY_RETENTION_MONTHS meses (padrão 24). Agendado diariamente.
 */
class AnonymizeCustomersCommand extends Command
{
    protected $signature = 'privacy:anonymize {--months= : sobrescreve o prazo de retenção}';

    protected $description = 'Anonimiza clientes e pedidos antigos (retenção da LGPD)';

    public function handle(TenantContext $context, CustomerPrivacy $privacy): int
    {
        $months = (int) ($this->option('months') ?: config('privacy.retention_months', 24));
        if ($months < 6) {
            $this->error('Prazo mínimo de 6 meses.');

            return self::FAILURE;
        }

        $before = Carbon::now()->subMonths($months);
        $total = 0;
        foreach (Tenant::query()->orderBy('id')->cursor() as $tenant) {
            $total += $context->run($tenant, fn () => $privacy->anonymizeOlderThan($before));
        }

        $this->info("Pedidos anonimizados: {$total} (anteriores a {$before->toDateString()}).");

        return self::SUCCESS;
    }
}
