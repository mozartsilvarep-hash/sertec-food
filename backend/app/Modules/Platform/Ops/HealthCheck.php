<?php

namespace App\Modules\Platform\Ops;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Saúde da instalação: banco, cache/Redis, fila (atraso) e agendador
 * (batimento gravado a cada minuto). Usado pela rota interna de saúde e
 * pelo comando ops:status.
 */
final class HealthCheck
{
    public const HEARTBEAT = 'ops:scheduler-heartbeat';

    /** Fila acima disso indica worker parado ou sobrecarregado. */
    public const QUEUE_WARN = 100;

    /**
     * @return array{ok: bool, checks: array<string, array{ok: bool, detail: string}>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->probe(fn () => Tenant::query()->count().' estabelecimentos'),
            'cache' => $this->probe(function () {
                Cache::put('ops:probe', $v = (string) random_int(1, PHP_INT_MAX), 10);

                return Cache::get('ops:probe') === $v ? 'ok' : throw new \RuntimeException('leitura divergente');
            }),
            'queue' => $this->probe(function () {
                $size = Queue::size();

                return $size > self::QUEUE_WARN ? throw new \RuntimeException("{$size} jobs na fila") : "{$size} na fila";
            }),
            'scheduler' => $this->probe(function () {
                $beat = Cache::get(self::HEARTBEAT);
                if (! is_string($beat)) {
                    throw new \RuntimeException('sem batimento (scheduler parado?)');
                }
                $age = Carbon::parse($beat)->diffInSeconds(now(), true);

                return $age > 180 ? throw new \RuntimeException("último batimento há {$age}s") : "há {$age}s";
            }),
        ];

        return ['ok' => collect($checks)->every(fn ($c) => $c['ok']), 'checks' => $checks];
    }

    public static function beat(): void
    {
        Cache::put(self::HEARTBEAT, now()->toIso8601String(), 600);
    }

    /**
     * @param  callable(): string  $fn
     * @return array{ok: bool, detail: string}
     */
    private function probe(callable $fn): array
    {
        try {
            return ['ok' => true, 'detail' => $fn()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 120)];
        }
    }
}
