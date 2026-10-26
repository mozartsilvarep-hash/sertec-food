<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Domains\CustomDomains;
use App\Modules\Tenancy\Enums\DomainType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Domínios próprios de um estabelecimento (superadmin).
 */
class AdminDomainController
{
    public function store(Request $request, Tenant $tenant, CustomDomains $domains, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $data = $request->validate(['hostname' => ['required', 'string', 'max:253']]);

        ['domain' => $domain, 'token' => $token] = $domains->add($tenant, $data['hostname']);
        $audit->record('tenant.domain_added', $tenant, ['hostname' => $domain->hostname], tenant: $tenant);

        return new JsonResponse(['data' => self::instructions($domain, $token)], 201);
    }

    public function regenerate(Request $request, Tenant $tenant, string $domain, CustomDomains $domains): JsonResponse
    {
        $this->requireSuperadmin($request);
        $model = $this->find($tenant, $domain);

        return new JsonResponse(['data' => self::instructions($model, $domains->regenerate($model))]);
    }

    public function verify(Request $request, Tenant $tenant, string $domain, CustomDomains $domains, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $model = $this->find($tenant, $domain);

        if (! $domains->verify($model)) {
            throw new ApiException(422, 'domain_not_verified', 'Ainda não encontramos o registro TXT em '.CustomDomains::TXT_PREFIX.$model->hostname.'. A propagação do DNS pode levar alguns minutos.');
        }

        $audit->record('tenant.domain_verified', $tenant, ['hostname' => $model->hostname], tenant: $tenant);

        return new JsonResponse(['data' => ['hostname' => $model->hostname, 'verified' => true]]);
    }

    public function primary(Request $request, Tenant $tenant, string $domain, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $model = $this->find($tenant, $domain);
        if ($model->verified_at === null) {
            throw new ApiException(409, 'domain_not_verified', 'Verifique o domínio antes de torná-lo principal.');
        }

        DB::transaction(function () use ($tenant, $model) {
            TenantDomain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->update(['is_primary' => false]);
            $model->forceFill(['is_primary' => true])->save();
        });
        $audit->record('tenant.domain_primary', $tenant, ['hostname' => $model->hostname], tenant: $tenant);

        return new JsonResponse(['data' => ['hostname' => $model->hostname, 'is_primary' => true]]);
    }

    public function destroy(Request $request, Tenant $tenant, string $domain, AuditLogger $audit): JsonResponse
    {
        $this->requireSuperadmin($request);
        $model = $this->find($tenant, $domain);
        if ($model->type !== DomainType::Custom) {
            throw new ApiException(409, 'domain_platform', 'O subdomínio da plataforma não pode ser removido.');
        }
        if ($model->is_primary) {
            throw new ApiException(409, 'domain_primary', 'Escolha outro domínio principal antes de remover este.');
        }

        $model->delete();
        $audit->record('tenant.domain_removed', $tenant, ['hostname' => $model->hostname], tenant: $tenant);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function instructions(TenantDomain $domain, string $token): array
    {
        return [
            'id' => (string) $domain->id,
            'hostname' => $domain->hostname,
            'verified' => false,
            'txt_name' => CustomDomains::TXT_PREFIX.$domain->hostname,
            'txt_value' => 'sertec-verify='.$token,
            // Depois de verificado, o domínio precisa apontar para a plataforma.
            'cname_target' => 'cname.'.config('tenancy.base_domain'),
        ];
    }

    private function find(Tenant $tenant, string $id): TenantDomain
    {
        return TenantDomain::query()->where('tenant_id', $tenant->id)->whereKey((int) $id)->first()
            ?? throw new ApiException(404, 'not_found', 'Domínio não encontrado.');
    }

    private function requireSuperadmin(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User || $user->platform_role !== PlatformRole::Superadmin) {
            throw ApiException::forbidden();
        }
    }
}
