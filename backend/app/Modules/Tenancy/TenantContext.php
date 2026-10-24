<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use Closure;

/**
 * Tenant corrente da requisição/job. Registrado como "scoped" no container:
 * é descartado entre requisições e entre jobs do worker.
 *
 * Fail-closed: qualquer leitura de model de tenant sem contexto lança
 * MissingTenantContext em vez de retornar dados de todos os tenants.
 *
 * Modo plataforma: usado apenas pelo painel SerTec, seeders e comandos de
 * console para operar entre tenants. Restrito por teste de arquitetura.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $platformMode = false;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): int
    {
        return (int) $this->get()->getKey();
    }

    /**
     * Executa $callback com $tenant como contexto e restaura o anterior,
     * mesmo em caso de exceção.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousMode = $this->platformMode;
        $this->tenant = $tenant;
        $this->platformMode = false;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->platformMode = $previousMode;
        }
    }

    /**
     * Executa $callback sem filtro de tenant (operação de plataforma).
     * Escritas continuam exigindo tenant_id explícito.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsPlatform(Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousMode = $this->platformMode;
        $this->tenant = null;
        $this->platformMode = true;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->platformMode = $previousMode;
        }
    }

    public function isPlatformMode(): bool
    {
        return $this->platformMode;
    }
}
