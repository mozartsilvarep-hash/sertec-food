<?php

namespace App\Modules\Platform\Ops;

use App\Modules\Platform\Domains\DnsResolver;

/**
 * Confere SPF, DKIM e DMARC do domínio que envia os e-mails (D102). Sem
 * eles, faturas e "esqueci a senha" tendem a cair no spam.
 */
final class MailDnsCheck
{
    /** Seletores DKIM mais comuns (aaPanel usa "default"; Zoho, o escolhido no painel). */
    public const SELECTORS = ['default', 'zmail', 'zoho', 'mail', 'dkim', 'sertec'];

    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * @param  list<string>  $selectors
     * @return array{domain: string, checks: array<string, array{ok: bool, detail: string, fix: string|null}>}
     */
    public function run(string $domain, array $selectors = [], ?string $serverIp = null): array
    {
        $domain = strtolower(trim($domain));
        $checks = [];

        $spf = array_values(array_filter($this->dns->txt($domain), fn (string $t) => str_starts_with(strtolower($t), 'v=spf1')));
        if (count($spf) !== 1) {
            $checks['spf'] = ['ok' => false, 'detail' => $spf === [] ? 'nenhum registro SPF' : count($spf).' registros SPF (só pode haver um)',
                'fix' => "TXT em {$domain}: v=spf1 include:zohomail.com".($serverIp ? " ip4:{$serverIp}" : '').' ~all'];
        } else {
            $value = $spf[0];
            $missing = [];
            // Quem envia é o servidor (Mail Server do aaPanel): o IP dele precisa estar no SPF.
            if ($serverIp !== null && ! str_contains($value, "ip4:{$serverIp}")) {
                $missing[] = "ip4:{$serverIp}";
            }
            $checks['spf'] = ['ok' => $missing === [], 'detail' => $value, 'fix' => $missing === [] ? null : 'Acrescente '.implode(' e ', $missing).' ao SPF.'];
        }

        $found = null;
        foreach (array_values(array_unique([...$selectors, ...self::SELECTORS])) as $sel) {
            $key = array_values(array_filter($this->dns->txt("{$sel}._domainkey.{$domain}"), fn (string $t) => str_contains($t, 'p=')));
            if ($key !== []) {
                $found = $sel;
                break;
            }
        }
        $checks['dkim'] = $found !== null
            ? ['ok' => true, 'detail' => "seletor {$found}", 'fix' => null]
            : ['ok' => false, 'detail' => 'nenhuma chave DKIM encontrada', 'fix' => 'No aaPanel: Mail Server › Domínios › '.$domain.' › DNS: copie o registro TXT "default._domainkey" (DKIM) para o DNS do domínio. Se o envio for pelo Zoho, use o seletor criado em Zoho Admin › Domínios › Autenticação de e-mail.'];

        $dmarc = array_values(array_filter($this->dns->txt("_dmarc.{$domain}"), fn (string $t) => str_starts_with(strtolower($t), 'v=dmarc1')));
        $checks['dmarc'] = $dmarc !== []
            ? ['ok' => true, 'detail' => $dmarc[0], 'fix' => null]
            : ['ok' => false, 'detail' => 'sem DMARC', 'fix' => "TXT em _dmarc.{$domain}: v=DMARC1; p=none; rua=mailto:postmaster@{$domain}"];

        return ['domain' => $domain, 'checks' => $checks];
    }
}
