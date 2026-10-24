<?php

namespace App\Modules\Tenancy\Schedule;

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\BranchOpeningHour;
use App\Modules\Tenancy\Models\BranchScheduleException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Agenda de funcionamento de uma unidade no fuso do tenant.
 *
 * Regras (desenho inspirado no WorkingSchedule do TastyIgniter, reescrito):
 * - período com fechamento <= abertura termina no dia seguinte (18:00–02:00);
 *   abertura == fechamento = 24 horas;
 * - exceções de uma data substituem o horário semanal daquela data, mas um
 *   período que começou na véspera continua valendo após a meia-noite;
 * - vários períodos por dia (almoço e jantar).
 */
final class OpeningSchedule
{
    /**
     * @param  array<int, list<array{0:string,1:string}>>  $weekly  weekday => [[opens, closes]]
     * @param  array<string, list<array{0:string,1:string}>>  $exceptions  'Y-m-d' => períodos ([] = fechado)
     */
    public function __construct(
        private readonly array $weekly,
        private readonly array $exceptions,
        private readonly string $timezone,
    ) {}

    public static function forBranch(Branch $branch, string $timezone): self
    {
        $weekly = [];
        foreach (BranchOpeningHour::query()->where('branch_id', $branch->id)->orderBy('opens_at')->get() as $row) {
            $weekly[$row->weekday][] = [substr($row->opens_at, 0, 5), substr($row->closes_at, 0, 5)];
        }

        $exceptions = [];
        $rows = BranchScheduleException::query()
            ->where('branch_id', $branch->id)
            ->where('date', '>=', CarbonImmutable::now($timezone)->subDay()->toDateString())
            ->orderBy('date')->orderBy('opens_at')
            ->get();

        foreach ($rows as $row) {
            $key = $row->date->toDateString();
            $exceptions[$key] ??= [];
            if (! $row->closed && $row->opens_at !== null && $row->closes_at !== null) {
                $exceptions[$key][] = [substr($row->opens_at, 0, 5), substr($row->closes_at, 0, 5)];
            }
        }

        return new self($weekly, $exceptions, $timezone);
    }

    /**
     * Períodos que COMEÇAM na data informada (fuso do tenant).
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function periodsStartingOn(CarbonImmutable $date): array
    {
        $day = $date->setTimezone($this->timezone)->startOfDay();
        $key = $day->toDateString();
        $definitions = array_key_exists($key, $this->exceptions)
            ? $this->exceptions[$key]
            : ($this->weekly[$day->dayOfWeek] ?? []);

        $periods = [];
        foreach ($definitions as [$opens, $closes]) {
            $start = $day->setTimeFromTimeString($opens);
            $end = $day->setTimeFromTimeString($closes);
            if ($end <= $start) {
                $end = $end->addDay();
            }
            $periods[] = ['start' => $start, 'end' => $end];
        }

        usort($periods, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $periods;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function currentPeriod(DateTimeInterface $at): ?array
    {
        $moment = CarbonImmutable::instance($at)->setTimezone($this->timezone);

        foreach ([$moment->subDay(), $moment] as $day) {
            foreach ($this->periodsStartingOn($day) as $period) {
                if ($moment >= $period['start'] && $moment < $period['end']) {
                    return $period;
                }
            }
        }

        return null;
    }

    public function isOpenAt(DateTimeInterface $at): bool
    {
        return $this->currentPeriod($at) !== null;
    }

    public function nextOpeningAt(DateTimeInterface $at, int $searchDays = 8): ?CarbonImmutable
    {
        $moment = CarbonImmutable::instance($at)->setTimezone($this->timezone);

        for ($i = 0; $i <= $searchDays; $i++) {
            foreach ($this->periodsStartingOn($moment->addDays($i)) as $period) {
                if ($period['start'] > $moment) {
                    return $period['start'];
                }
            }
        }

        return null;
    }

    public function hasAnyHours(): bool
    {
        return $this->weekly !== [];
    }

    /**
     * @return array{open: bool, closes_at: string|null, next_opening_at: string|null}
     */
    public function status(DateTimeInterface $at): array
    {
        $current = $this->currentPeriod($at);

        return [
            'open' => $current !== null,
            'closes_at' => $current !== null ? $current['end']->toIso8601String() : null,
            'next_opening_at' => $current === null ? $this->nextOpeningAt($at)?->toIso8601String() : null,
        ];
    }
}
