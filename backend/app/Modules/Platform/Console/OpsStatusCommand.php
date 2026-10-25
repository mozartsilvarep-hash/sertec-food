<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Ops\HealthCheck;
use Illuminate\Console\Command;

class OpsStatusCommand extends Command
{
    protected $signature = 'ops:status';

    protected $description = 'Mostra a saúde da instalação (banco, cache, fila e agendador)';

    public function handle(HealthCheck $health): int
    {
        $result = $health->run();
        $this->table(['Verificação', 'OK', 'Detalhe'], collect($result['checks'])->map(
            fn (array $c, string $name) => [$name, $c['ok'] ? 'sim' : 'NÃO', $c['detail']],
        )->values()->all());

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
