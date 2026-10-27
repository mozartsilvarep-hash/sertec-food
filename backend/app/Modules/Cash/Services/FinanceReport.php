<?php

namespace App\Modules\Cash\Services;

use App\Modules\Cash\Enums\CashCategory;
use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Cash\Models\CashEntry;
use App\Modules\Cash\Models\FixedCost;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Resultado do período (DRE simplificado), a partir do livro-caixa:
 *
 *   vendas (balcão + pedidos) − estornos − taxas das formas de pagamento
 *   + outras entradas − despesas lançadas − custos fixos (proporcionais)
 *   = resultado
 *
 * Reforço e sangria só movem dinheiro (gaveta ↔ banco/cofre): aparecem à
 * parte e não mexem no resultado. Saída ligada a um custo fixo não conta de
 * novo (o custo fixo já entra pelo valor proporcional do período).
 */
final class FinanceReport
{
    /**
     * @param  list<int>|null  $branchIds  null = todas as unidades
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds, string $timezone): array
    {
        $rows = CashEntry::query()->valid()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds ?? []))
            ->where('occurred_at', '>=', $from->utc())->where('occurred_at', '<=', $to->utc())
            ->get(['type', 'category', 'method', 'amount', 'fee_amount', 'fixed_cost_id', 'occurred_at']);

        $sales = 0;
        $fees = 0;
        $refunds = 0;
        $otherIn = 0;
        $supply = 0;
        $withdrawal = 0;
        $fixedPaid = 0;
        $methods = [];
        $expenses = [];
        $days = [];
        foreach ($rows as $r) {
            $cents = Money::toCents((string) $r->amount);
            $day = $r->occurred_at->setTimezone($timezone)->format('Y-m-d');
            $days[$day] ??= ['date' => $day, 'sales' => 0, 'fees' => 0, 'expenses' => 0];
            switch ($r->category) {
                case CashCategory::CounterSale:
                case CashCategory::Order:
                    $fee = Money::toCents((string) $r->fee_amount);
                    $sales += $cents;
                    $fees += $fee;
                    $m = $r->method->value;
                    $methods[$m] ??= ['method' => $m, 'label' => $r->method->label(), 'count' => 0, 'gross' => 0, 'fees' => 0];
                    $methods[$m]['count']++;
                    $methods[$m]['gross'] += $cents;
                    $methods[$m]['fees'] += $fee;
                    $days[$day]['sales'] += $cents;
                    $days[$day]['fees'] += $fee;
                    break;
                case CashCategory::Refund:
                    $refunds += $cents;
                    $days[$day]['sales'] -= $cents;
                    break;
                case CashCategory::OtherIn:
                    $otherIn += $cents;
                    break;
                case CashCategory::Supply:
                    $supply += $cents;
                    break;
                case CashCategory::Withdrawal:
                    $withdrawal += $cents;
                    break;
                default:
                    if ($r->fixed_cost_id !== null) {
                        $fixedPaid += $cents;
                        break;
                    }
                    $c = $r->category->value;
                    $expenses[$c] ??= ['category' => $c, 'label' => $r->category->label(), 'count' => 0, 'total' => 0];
                    $expenses[$c]['count']++;
                    $expenses[$c]['total'] += $cents;
                    $days[$day]['expenses'] += $cents;
            }
        }

        // Custos fixos ativos, proporcionais aos dias do período.
        $fixed = [];
        $fixedTotal = 0;
        // Filtrando por unidade: custos dela + os do restaurante todo.
        $costs = FixedCost::query()->where('active', true)
            ->when($branchIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $branchIds ?? [])))
            ->orderBy('name')->get();
        foreach ($costs as $cost) {
            $share = self::prorata(Money::toCents((string) $cost->amount), $from->setTimezone($timezone), $to->setTimezone($timezone));
            $fixedTotal += $share;
            $fixed[] = ['id' => $cost->public_id, 'name' => $cost->name, 'monthly' => $cost->amount, 'period' => Money::format($share)];
        }

        $variable = array_sum(array_column($expenses, 'total'));
        $result = $sales - $refunds - $fees + $otherIn - $variable - $fixedTotal;
        ksort($days);
        usort($expenses, fn ($a, $b) => $b['total'] <=> $a['total']);
        uasort($methods, fn ($a, $b) => $b['gross'] <=> $a['gross']);
        $f = fn (int $c) => Money::format($c);

        return [
            'sales' => $f($sales),
            'refunds' => $f($refunds),
            'fees' => $f($fees),
            'net_sales' => $f($sales - $refunds - $fees),
            'other_in' => $f($otherIn),
            'expenses' => $f($variable),
            'fixed_costs' => $f($fixedTotal),
            'fixed_paid_in_cash' => $f($fixedPaid),
            'result' => $f($result),
            // Margem sobre as vendas, em pontos-base (1234 = 12,34%).
            'margin_bp' => $sales > 0 ? intdiv($result * 10000, $sales) : null,
            'by_method' => array_values(array_map(fn ($m) => [
                ...$m, 'gross' => $f($m['gross']), 'fees' => $f($m['fees']), 'net' => $f($m['gross'] - $m['fees']),
            ], $methods)),
            'expenses_by_category' => array_map(fn ($e) => [...$e, 'total' => $f($e['total'])], $expenses),
            'fixed_items' => $fixed,
            'cash_moves' => ['supply' => $f($supply), 'withdrawal' => $f($withdrawal)],
            'by_day' => array_values(array_map(fn ($d) => [...$d, 'sales' => $f($d['sales']), 'fees' => $f($d['fees']), 'expenses' => $f($d['expenses'])], $days)),
        ];
    }

    /** Valor mensal → parte do período (cada dia vale 1/dias-do-mês daquele mês). */
    public static function prorata(int $monthlyCents, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $total = 0.0;
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $total += $monthlyCents / $d->daysInMonth;
        }

        return (int) round($total);
    }

    /**
     * Formas que podem ter taxa configurada.
     *
     * @return list<CashMethod>
     */
    public static function feeMethods(): array
    {
        return [CashMethod::Credit, CashMethod::Debit, CashMethod::Pix, CashMethod::Card, CashMethod::Online, CashMethod::Other, CashMethod::Cash];
    }
}
