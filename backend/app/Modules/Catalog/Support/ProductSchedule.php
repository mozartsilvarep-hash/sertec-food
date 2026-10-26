<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dias da semana e horário em que um produto pode ser pedido (ex.: bolo X só
 * sábado e domingo; almoço das 11h às 15h). Avaliado no fuso do restaurante.
 * Sem dias e sem horário = sempre disponível. Faixa que passa da meia-noite
 * (ex.: 18h às 2h) é aceita.
 */
final class ProductSchedule
{
    private const DAYS = ['dom.', 'seg.', 'ter.', 'qua.', 'qui.', 'sex.', 'sáb.'];

    /**
     * @param  list<int>|null  $days
     */
    private function __construct(
        private readonly ?array $days,
        private readonly ?string $from,
        private readonly ?string $until,
        private readonly string $timezone,
    ) {}

    public static function for(Product $product): self
    {
        $days = is_array($product->available_days) && $product->available_days !== [] && count($product->available_days) < 7
            ? array_values(array_unique(array_map('intval', $product->available_days)))
            : null;
        if ($days !== null) {
            sort($days);
        }
        $from = $product->available_from ? substr($product->available_from, 0, 5) : null;
        $until = $product->available_until ? substr($product->available_until, 0, 5) : null;
        if ($from === null || $until === null || $from === $until) {
            $from = $until = null;
        }

        return new self($days, $from, $until, app(TenantContext::class)->current()->timezone ?? (string) config('tenancy.default_timezone'));
    }

    public function isRestricted(): bool
    {
        return $this->days !== null || $this->from !== null;
    }

    public function isAvailableAt(?DateTimeInterface $at = null): bool
    {
        if (! $this->isRestricted()) {
            return true;
        }
        $local = CarbonImmutable::instance($at ?? now())->setTimezone($this->timezone);
        $time = $local->format('H:i');
        $day = $local->dayOfWeek;

        if ($this->from !== null && $this->until !== null) {
            if ($this->from < $this->until) {
                if ($time < $this->from || $time >= $this->until) {
                    return false;
                }
            } else {
                // Passa da meia-noite: depois da 0h vale o dia em que a faixa começou.
                if ($time < $this->until) {
                    $day = ($day + 6) % 7;
                } elseif ($time < $this->from) {
                    return false;
                }
            }
        }

        return $this->days === null || in_array($day, $this->days, true);
    }

    /** "Só sáb. e dom.", "Seg. a sex., das 11h às 15h", "Das 18h às 22h"; null se sempre disponível. */
    public function label(): ?string
    {
        if (! $this->isRestricted()) {
            return null;
        }
        $parts = [];
        if ($this->days !== null) {
            $parts[] = 'só '.$this->daysLabel($this->days);
        }
        if ($this->from !== null && $this->until !== null) {
            $parts[] = 'das '.self::hour($this->from).' às '.self::hour($this->until);
        }

        return ucfirst(implode(', ', $parts));
    }

    /** @return array{days: list<int>|null, from: string|null, until: string|null} */
    public function toArray(): array
    {
        return ['days' => $this->days, 'from' => $this->from, 'until' => $this->until];
    }

    /** @param list<int> $days */
    private function daysLabel(array $days): string
    {
        // Seg. a sex. (1..5) e fim de semana são os casos mais comuns.
        if ($days === [1, 2, 3, 4, 5]) {
            return 'de seg. a sex.';
        }
        if ($days === [0, 6]) {
            return 'sáb. e dom.';
        }
        $runs = [];
        foreach ($days as $d) {
            $last = count($runs) - 1;
            if ($last >= 0 && end($runs[$last]) === $d - 1) {
                $runs[$last][] = $d;
            } else {
                $runs[] = [$d];
            }
        }
        $names = [];
        foreach ($runs as $r) {
            if (count($r) >= 3) {
                $names[] = self::DAYS[$r[0]].' a '.self::DAYS[end($r)];
            } else {
                array_push($names, ...array_map(fn ($d) => self::DAYS[$d], $r));
            }
        }
        $lastName = array_pop($names);

        return $names === [] ? (string) $lastName : implode(', ', $names).' e '.$lastName;
    }

    private static function hour(string $hm): string
    {
        [$h, $m] = explode(':', $hm);

        return ((int) $h).'h'.($m === '00' ? '' : $m);
    }
}
