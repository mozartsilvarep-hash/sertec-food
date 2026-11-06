<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Ops\MailDnsCheck;
use Illuminate\Console\Command;

class MailDnsCommand extends Command
{
    protected $signature = 'ops:mail-dns
        {--domain= : Domínio que envia (padrão: o do MAIL_FROM_ADDRESS)}
        {--selector=* : Seletor DKIM do Zoho, se não for um dos comuns}
        {--ip= : IP do servidor que envia direto (opcional)}';

    protected $description = 'Confere SPF, DKIM e DMARC do domínio dos e-mails';

    public function handle(MailDnsCheck $check): int
    {
        $from = (string) config('mail.from.address');
        $domain = (string) ($this->option('domain') ?: substr((string) strrchr($from, '@'), 1));
        if ($domain === '') {
            $this->error('Informe --domain (MAIL_FROM_ADDRESS vazio).');

            return self::FAILURE;
        }
        /** @var list<string> $selectors */
        $selectors = array_values(array_filter((array) $this->option('selector')));
        $result = $check->run($domain, $selectors, $this->option('ip') ?: null);

        $this->info("Domínio: {$result['domain']}");
        $this->table(['Registro', 'OK', 'Encontrado'], collect($result['checks'])->map(
            fn (array $c, string $name) => [strtoupper($name), $c['ok'] ? 'sim' : 'NÃO', $c['detail']],
        )->values()->all());
        foreach ($result['checks'] as $name => $c) {
            if ($c['fix'] !== null) {
                $this->warn(strtoupper($name).': '.$c['fix']);
            }
        }

        return collect($result['checks'])->every(fn ($c) => $c['ok']) ? self::SUCCESS : self::FAILURE;
    }
}
