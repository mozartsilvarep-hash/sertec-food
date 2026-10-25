<?php

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Tenancy\Models\BranchOpeningHour;
use App\Modules\Tenancy\Models\BranchScheduleException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Só desenvolvimento: deixa os estabelecimentos de demonstração prontos para
 * os testes ponta a ponta (e2e/README.md) — mesma senha para os usuários
 * *.localhost, lojas abertas a qualquer hora (sem horários) e Mercado Pago
 * desconectado.
 */
class PrepareE2eCommand extends Command
{
    protected $signature = 'demo:prepare-e2e {password : senha para os usuários de demonstração (mín. 10)}';

    protected $description = 'Prepara os dados de demonstração para os testes E2E (somente local)';

    public function handle(TenantContext $context): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Disponível apenas em APP_ENV=local.');

            return self::FAILURE;
        }

        $password = (string) $this->argument('password');
        if (strlen($password) < 10) {
            $this->error('Use pelo menos 10 caracteres.');

            return self::FAILURE;
        }

        User::query()->where('email', 'like', '%.localhost')->each(fn (User $u) => $u->forceFill(['password' => $password])->save());

        foreach (Tenant::query()->whereIn('slug', ['pizzaria-demo', 'burger-demo'])->get() as $tenant) {
            $context->run($tenant, function () {
                BranchOpeningHour::query()->delete();
                BranchScheduleException::query()->delete();
                IntegrationConnection::query()->delete();
            });
        }

        $this->info('Demonstração pronta para os testes E2E.');

        return self::SUCCESS;
    }
}
