<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Tenants\TenantProvisioner;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {slug : Subdomínio (ex.: pizzaria-do-ze)}
        {name : Nome do estabelecimento}
        {--owner-email= : E-mail do proprietário}
        {--owner-name= : Nome do proprietário}
        {--plan= : Slug do plano (opcional)}
        {--timezone= : Fuso (padrão: tenancy.default_timezone)}
        {--activate : Ativa imediatamente (padrão: pending, ativação manual)}';

    protected $description = 'Cria um estabelecimento (tenant) com subdomínio, unidade Matriz e proprietário';

    public function handle(TenantProvisioner $provisioner): int
    {
        $email = (string) ($this->option('owner-email') ?: $this->ask('E-mail do proprietário'));
        $ownerName = (string) ($this->option('owner-name') ?: $this->ask('Nome do proprietário'));

        try {
            $result = $provisioner->provision(
                name: (string) $this->argument('name'),
                slug: (string) $this->argument('slug'),
                ownerEmail: $email,
                ownerName: $ownerName,
                planSlug: $this->option('plan') ?: null,
                activate: (bool) $this->option('activate'),
                timezone: $this->option('timezone') ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tenant = $result['tenant'];
        $this->info("Tenant criado: {$tenant->name} ({$tenant->slug}) — status {$tenant->status->value}");
        $this->line('Host: '.$tenant->slug.'.'.config('tenancy.base_domain'));

        if ($result['owner_password'] !== null) {
            $this->warn('Senha inicial do proprietário (exibida uma única vez): '.$result['owner_password']);
        } else {
            $this->line('Proprietário já existia; senha não alterada.');
        }

        return self::SUCCESS;
    }
}
