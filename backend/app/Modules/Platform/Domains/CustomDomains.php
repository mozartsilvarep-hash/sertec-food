<?php

namespace App\Modules\Platform\Domains;

use App\Http\Errors\ApiException;
use App\Modules\Tenancy\Enums\DomainType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Domínio próprio do restaurante (ex.: pedidos.pizzariax.com.br).
 * Posse comprovada por TXT em _sertec-verify.{host} = "sertec-verify={token}".
 * Só domínio verificado resolve o tenant e recebe certificado (TLS ask).
 */
final class CustomDomains
{
    public const TXT_PREFIX = '_sertec-verify.';

    public function __construct(private readonly DnsResolver $dns) {}

    /** @return array{domain: TenantDomain, token: string} */
    public function add(Tenant $tenant, string $hostname): array
    {
        $host = TenantDomain::normalize($hostname);
        $base = (string) config('tenancy.base_domain');

        if (! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            throw new ApiException(422, 'domain_invalid', 'Informe um domínio válido, ex.: pedidos.seurestaurante.com.br');
        }
        if ($host === $base || str_ends_with($host, '.'.$base) || $host === config('tenancy.admin_host')) {
            throw new ApiException(422, 'domain_reserved', 'Este domínio pertence à plataforma.');
        }
        if (TenantDomain::query()->where('hostname', $host)->exists()) {
            throw new ApiException(409, 'domain_taken', 'Este domínio já está cadastrado.');
        }

        $token = Str::lower(Str::random(32));
        $domain = new TenantDomain([
            'tenant_id' => $tenant->id,
            'hostname' => $host,
            'type' => DomainType::Custom,
            'verified_at' => null,
            'is_primary' => false,
        ]);
        $domain->forceFill(['verification_token_hash' => hash('sha256', $token)])->save();

        return ['domain' => $domain, 'token' => $token];
    }

    /** Gera um novo token (o anterior deixa de valer). */
    public function regenerate(TenantDomain $domain): string
    {
        $token = Str::lower(Str::random(32));
        $domain->forceFill(['verification_token_hash' => hash('sha256', $token), 'verified_at' => null])->save();

        return $token;
    }

    public function verify(TenantDomain $domain): bool
    {
        if ($domain->type !== DomainType::Custom || $domain->verified_at !== null) {
            return $domain->verified_at !== null;
        }

        foreach ($this->dns->txt(self::TXT_PREFIX.$domain->hostname) as $value) {
            $value = trim($value, ' "');
            if (str_starts_with($value, 'sertec-verify=')
                && $domain->getAttribute('verification_token_hash') !== null
                && hash_equals((string) $domain->getAttribute('verification_token_hash'), hash('sha256', substr($value, strlen('sertec-verify='))))) {
                $domain->forceFill(['verified_at' => Carbon::now()])->save();

                return true;
            }
        }

        return false;
    }
}
