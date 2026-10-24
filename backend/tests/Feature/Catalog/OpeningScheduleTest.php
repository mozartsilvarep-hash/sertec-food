<?php

use App\Modules\Tenancy\Schedule\OpeningSchedule;
use Carbon\CarbonImmutable;

const TZ = 'America/Maceio';

function at(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, TZ);
}

// 2026-10-23 é sexta-feira (weekday 5).
it('período que passa da meia-noite continua aberto na madrugada seguinte', function () {
    $s = new OpeningSchedule([5 => [['18:00', '02:00']]], [], TZ);

    expect($s->isOpenAt(at('2026-10-23 17:59')))->toBeFalse()
        ->and($s->isOpenAt(at('2026-10-23 18:00')))->toBeTrue()
        ->and($s->isOpenAt(at('2026-10-24 01:59')))->toBeTrue()
        ->and($s->isOpenAt(at('2026-10-24 02:00')))->toBeFalse();
});

it('aceita vários períodos no mesmo dia e calcula a próxima abertura', function () {
    $s = new OpeningSchedule([5 => [['11:00', '15:00'], ['18:00', '23:00']]], [], TZ);

    expect($s->isOpenAt(at('2026-10-23 16:00')))->toBeFalse()
        ->and($s->nextOpeningAt(at('2026-10-23 16:00'))?->format('Y-m-d H:i'))->toBe('2026-10-23 18:00')
        ->and($s->nextOpeningAt(at('2026-10-23 23:30'))?->format('Y-m-d H:i'))->toBe('2026-10-30 11:00');
});

it('exceção fechada substitui o dia, mas não corta o período iniciado na véspera', function () {
    $s = new OpeningSchedule(
        [5 => [['18:00', '02:00']], 6 => [['18:00', '02:00']]],
        ['2026-10-24' => []],
        TZ,
    );

    expect($s->isOpenAt(at('2026-10-24 01:00')))->toBeTrue()   // ainda da sexta
        ->and($s->isOpenAt(at('2026-10-24 20:00')))->toBeFalse(); // sábado fechado (feriado)
});

it('exceção com horário especial vale só naquela data', function () {
    $s = new OpeningSchedule([5 => [['18:00', '23:00']]], ['2026-10-23' => [['10:00', '14:00']]], TZ);

    expect($s->isOpenAt(at('2026-10-23 12:00')))->toBeTrue()
        ->and($s->isOpenAt(at('2026-10-23 19:00')))->toBeFalse()
        ->and($s->isOpenAt(at('2026-10-30 19:00')))->toBeTrue();
});

it('abertura igual ao fechamento significa 24 horas', function () {
    $s = new OpeningSchedule([5 => [['00:00', '00:00']]], [], TZ);

    expect($s->isOpenAt(at('2026-10-23 03:00')))->toBeTrue()
        ->and($s->isOpenAt(at('2026-10-23 23:59')))->toBeTrue()
        ->and($s->isOpenAt(at('2026-10-24 00:00')))->toBeFalse();
});

it('compara no fuso do estabelecimento, independente do fuso do servidor', function () {
    $s = new OpeningSchedule([5 => [['18:00', '23:00']]], [], TZ);

    // 21:30 UTC = 18:30 em Maceió
    expect($s->isOpenAt(CarbonImmutable::parse('2026-10-23 21:30', 'UTC')))->toBeTrue();
    expect($s->status(CarbonImmutable::parse('2026-10-23 21:30', 'UTC'))['closes_at'])->toBe('2026-10-23T23:00:00-03:00');
});

it('sem horário cadastrado fica fechado e sem próxima abertura', function () {
    $s = new OpeningSchedule([], [], TZ);

    expect($s->status(at('2026-10-23 12:00')))->toBe(['open' => false, 'closes_at' => null, 'next_opening_at' => null])
        ->and($s->hasAnyHours())->toBeFalse();
});
