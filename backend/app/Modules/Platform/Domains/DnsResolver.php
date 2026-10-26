<?php

namespace App\Modules\Platform\Domains;

/**
 * Consulta DNS (porta para testes). Produção usa dns_get_record do PHP.
 */
interface DnsResolver
{
    /** @return list<string> valores TXT de $name */
    public function txt(string $name): array;
}
