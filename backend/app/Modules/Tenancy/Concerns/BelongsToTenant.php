<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Exceptions\CrossTenantWrite;
use App\Modules\Tenancy\Exceptions\MissingTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Obrigatório em todo model cuja tabela tem tenant_id (verificado por teste).
 *
 * - Leitura: global scope filtra pelo tenant do contexto (sem contexto = exceção).
 * - Criação: preenche tenant_id a partir do contexto; tenant_id divergente = exceção.
 * - Atualização: tenant_id é imutável.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($context->isPlatformMode()) {
                if ($model->getAttribute('tenant_id') === null) {
                    throw new MissingTenantContext('Platform writes must set tenant_id explicitly.');
                }

                return;
            }

            $currentId = $context->id();
            $given = $model->getAttribute('tenant_id');

            if ($given === null) {
                $model->setAttribute('tenant_id', $currentId);
            } elseif ((int) $given !== $currentId) {
                throw new CrossTenantWrite;
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new CrossTenantWrite('tenant_id is immutable.');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
