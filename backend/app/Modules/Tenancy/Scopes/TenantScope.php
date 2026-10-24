<?php

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatformMode()) {
            return;
        }

        // id() lança MissingTenantContext quando não há tenant: fail-closed.
        $builder->where($model->qualifyColumn('tenant_id'), $context->id());
    }
}
