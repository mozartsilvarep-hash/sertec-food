<?php

namespace App\Support\Http;

final class SystemPublicHostResolver implements PublicHostResolver
{
    public function resolve(string $host): ?string
    {
        $ips = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
        }
        if ($ips === []) {
            return null;
        }
        // Todos os endereços precisam ser públicos: um só interno já recusa.
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
